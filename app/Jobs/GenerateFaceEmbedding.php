<?php

namespace App\Jobs;

use App\Domain\Enrollment\Models\FaceTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class GenerateFaceEmbedding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 120;
    
    public function __construct(
        public string $studentId,
        public string $tmpPath,
        public ?string $photoPath = null,
        public bool $deleteAfter = true,
        public ?string $modelVersion = null,
        public bool $allowDuplicate = false,
        public ?int $quarantineId = null,
        public ?int $actorId = null
    ) {
        $this->onQueue('enrollments');
    }

    public function handle(): void
    {
        if (! file_exists($this->tmpPath)) {
            Log::error("GenerateFaceEmbedding failed: Tmp file missing", ['path' => $this->tmpPath]);
            return;
        }

        // Privacy guard: Ensure active biometric consent exists before processing (UU PDP No. 27/2022)
        $student = \App\Domain\Student\Models\Student::find($this->studentId);
        if (!$student || !$student->hasActiveConsent()) {
            Log::warning('GenerateFaceEmbedding aborted: Active biometric consent not found or withdrawn', [
                'student_id' => $this->studentId,
            ]);
            if ($this->deleteAfter) {
                @unlink($this->tmpPath);
            }
            return;
        }

        try {
            $pythonBin = config('app.python_bin');
            $script = base_path('packages/face_core/extract.py');
            $detectorModel = base_path('packages/models/detector.onnx');
            $recognizerModel = base_path('packages/models/recognizer.onnx');
            



            $result = Process::path(base_path())
                ->timeout(120)
                ->env(['PYTHONPATH' => base_path('packages')])
                ->run([
                    $pythonBin,
                    $script,
                    $detectorModel,
                    $recognizerModel,
                    $this->tmpPath
                ]);
            
            if (!$result->successful()) {
                throw new \RuntimeException("Python extraction failed: " . $result->errorOutput());
            }

            $output = json_decode($result->output(), true);
            if (!$output || !isset($output['success']) || !$output['success']) {
                $err = $output['error'] ?? 'Unknown error';
                throw new \RuntimeException("Python extraction error: $err");
            }
            
            $stdout = base64_decode($output['embedding_b64']);

            if (strlen($stdout) !== 2048) {
                throw new \RuntimeException("Invalid embedding size. Expected 2048 bytes (512d), got " . strlen($stdout));
            }

            // Guard 1:N deduplikasi cloud sebelum enkripsi & penyimpanan
            if (!$this->allowDuplicate) {
                $detector = app(\App\Domain\Enrollment\Services\BiometricDuplicateDetector::class);
                $dup = $detector->detect($stdout, $this->studentId);
                if ($dup) {
                    Log::warning('Face enrollment rejected due to duplicate detection', [
                        'candidate_student_id' => $this->studentId,
                        'matched_student_id' => $dup['matched_student_id'],
                        'similarity' => $dup['similarity'],
                    ]);
                    throw new \App\Domain\Enrollment\Exceptions\DuplicateFaceException(
                        $dup['matched_student_id'],
                        $dup['similarity']
                    );
                }
            }
            
            // Apply AES-256-GCM encryption
            $encryptionService = app(\App\Domain\Enrollment\Services\FaceEncryptionService::class);
            $usedModelVersion = $this->modelVersion ?? config('app.model_version');
            $encryptedBlob = $encryptionService->encrypt($stdout, $this->studentId, $usedModelVersion);

            $hash = hash('sha256', $stdout);

            DB::transaction(function () use ($encryptedBlob, $hash, $usedModelVersion) {
                $data = [
                    'embedding_enc' => base64_encode($encryptedBlob),
                    'key_id' => 'default_key', // This should match edge engine's configured key
                    'model_version' => $usedModelVersion,
                    'embedding_hash' => $hash,
                ];
                
                if ($this->photoPath) {
                    $data['photo_path'] = $this->photoPath;
                }

                $template = FaceTemplate::withTrashed()->where('student_id', $this->studentId)->first();
                if ($template) {
                    if ($template->trashed()) {
                        $template->restore();
                    }
                    $template->update($data);
                } else {
                    FaceTemplate::create(array_merge(['student_id' => $this->studentId], $data));
                }

                // If dispatched for a specific quarantine incident, resolve only that incident
                if ($this->quarantineId) {
                    \App\Domain\Enrollment\Models\BiometricQuarantine::where('id', $this->quarantineId)
                        ->where('status', 'quarantined')
                        ->update([
                            'status'           => 'resolved',
                            'resolved_at'      => now(),
                            'resolved_by'      => $this->actorId,
                            'resolution_notes' => 'Berhasil diekstrak dan dienkripsi ulang melalui antrean pemrosesan foto.',
                        ]);
                }
            });

            Log::info('Face enrollment generated successfully', ['student_id' => $this->studentId]);

            // Only delete tmp file on successful completion
            if ($this->deleteAfter) {
                @unlink($this->tmpPath);
            }

        } catch (\Throwable $e) {
            // Rethrow so queue retry mechanism works; do NOT delete tmpPath here so attempt 2 can retry
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->deleteAfter) {
            @unlink($this->tmpPath);
        }
        Log::error('Face enrollment job failed: ' . $exception->getMessage(), [
            'student_id' => $this->studentId,
            'exception' => $exception
        ]);
    }
}

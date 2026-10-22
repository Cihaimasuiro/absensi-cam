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
            $stdout = app(\App\Domain\Enrollment\Services\FaceExtractor::class)->extract($this->tmpPath);

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
            $msg = $e->getMessage();
            $isNonRetryable = $e instanceof \App\Domain\Enrollment\Exceptions\DuplicateFaceException
                || str_contains($msg, 'No face detected')
                || str_contains($msg, 'Multiple faces detected')
                || str_contains($msg, 'Face alignment failed')
                || str_contains($msg, 'Could not read image')
                || str_contains($msg, 'Face extraction image file not found');

            if ($isNonRetryable) {
                $this->fail($e);
            }

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

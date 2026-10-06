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
    public $timeout = 60;
    
    public function __construct(
        public string $studentId,
        public string $tmpPath,
        public ?string $photoPath = null,
        public bool $deleteAfter = true,
        public string $modelVersion = '3d9f1f77896fb3d1'
    ) {
        $this->onQueue('enrollments');
    }

    public function handle(): void
    {
        if (! file_exists($this->tmpPath)) {
            Log::error("GenerateFaceEmbedding failed: Tmp file missing", ['path' => $this->tmpPath]);
            return;
        }

        try {
            $pythonBin = env('PYTHON_BIN', base_path('clients/edge-engine/venv/Scripts/python.exe'));
            $script = base_path('packages/face_core/extract.py');
            $detectorModel = base_path('clients/edge-engine/assets/models/detector.onnx');
            $recognizerModel = base_path('clients/edge-engine/assets/models/recognizer.onnx');
            


            $process = new \Symfony\Component\Process\Process([
                $pythonBin,
                $script,
                $detectorModel,
                $recognizerModel,
                $this->tmpPath
            ], base_path(), ['PYTHONPATH' => base_path('packages')]);
            
            $process->run();
            
            if (!$process->isSuccessful()) {
                throw new \RuntimeException("Python extraction failed: " . $process->getErrorOutput());
            }

            $output = json_decode($process->getOutput(), true);
            if (!$output || !isset($output['success']) || !$output['success']) {
                $err = $output['error'] ?? 'Unknown error';
                throw new \RuntimeException("Python extraction error: $err");
            }
            
            $stdout = base64_decode($output['embedding_b64']);

            if (strlen($stdout) !== 2048) {
                throw new \RuntimeException("Invalid embedding size. Expected 2048 bytes (512d), got " . strlen($stdout));
            }
            
            // Apply AES-256-GCM encryption
            $encryptionService = app(\App\Domain\Enrollment\Services\FaceEncryptionService::class);
            $encryptedBlob = $encryptionService->encrypt($stdout, $this->studentId, $this->modelVersion);

            $hash = hash('sha256', $stdout);

            DB::transaction(function () use ($encryptedBlob, $hash) {
                $data = [
                    'embedding_enc' => base64_encode($encryptedBlob),
                    'key_id' => 'default_key', // This should match edge engine's configured key
                    'model_version' => $this->modelVersion,
                    'embedding_hash' => $hash,
                    'deleted_at' => null
                ];
                
                if ($this->photoPath) {
                    $data['photo_path'] = $this->photoPath;
                }

                FaceTemplate::updateOrCreate(
                    ['student_id' => $this->studentId],
                    $data
                );
            });

            Log::info('Face enrollment generated successfully', ['student_id' => $this->studentId]);

        } finally {
            if ($this->deleteAfter) {
                @unlink($this->tmpPath);
            }
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

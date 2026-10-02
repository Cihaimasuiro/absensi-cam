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
        public string $tmpPath
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
            $bin = env('FACE_EMBED_BIN', '/usr/local/bin/face-embed');
            
            // --- PONYTAIL LOCAL DEV BYPASS ---
            // Jika di local development dan binary face-embed tidak ada, buat dummy embedding saja
            // supaya UI bisa menampilkan status "Terdaftar" tanpa error.
            if (app()->environment('local') && (!file_exists($bin) && PHP_OS_FAMILY === 'Windows')) {
                $stdout = random_bytes(2048);
                $key = random_bytes(32);
            } else {
                $keyBase64 = env('ENROLLMENT_EMBED_KEY');
                
                if (!$keyBase64) {
                    throw new \RuntimeException('ENROLLMENT_EMBED_KEY is not set');
                }
                
                $key = base64_decode($keyBase64);
                if (strlen($key) !== 32) {
                    throw new \RuntimeException('ENROLLMENT_EMBED_KEY must be exactly 32 bytes when decoded');
                }

                // Command: face-embed --model sface-2021dec --input {path} --output -
                $cmd = escapeshellcmd($bin) . " --model sface-2021dec --input " . escapeshellarg($this->tmpPath) . " --output -";
                
                $descriptors = [
                    1 => ['pipe', 'w'], // stdout
                    2 => ['pipe', 'w'], // stderr
                ];
                
                $process = proc_open($cmd, $descriptors, $pipes);
                
                if (!is_resource($process)) {
                    throw new \RuntimeException("Failed to start face-embed process");
                }
                
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                
                fclose($pipes[1]);
                fclose($pipes[2]);
                
                $exitCode = proc_close($process);
                
                if ($exitCode !== 0) {
                    throw new \RuntimeException("face-embed failed (Exit $exitCode): $stderr");
                }

                if (strlen($stdout) !== 2048) {
                    throw new \RuntimeException("Invalid embedding size. Expected 2048 bytes, got " . strlen($stdout));
                }
            }
            
            // AES-256-GCM Encrypt
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($stdout, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
            
            if ($ciphertext === false) {
                throw new \RuntimeException("AES-256-GCM encryption failed");
            }

            $encryptedBlob = $iv . $tag . $ciphertext;
            $hash = hash('sha256', $stdout);

            DB::transaction(function () use ($encryptedBlob, $hash) {
                // Get next cursor
                $result = DB::selectOne('SELECT COALESCE(MAX(version_cursor), 0) + 1 AS next FROM face_templates');
                $nextCursor = $result->next;

                FaceTemplate::updateOrCreate(
                    ['student_id' => $this->studentId],
                    [
                        'embedding_enc' => base64_encode($encryptedBlob),
                        'model_version' => 'sface-2021dec',
                        'version_cursor' => $nextCursor,
                        'embedding_hash' => $hash,
                        'deleted_at' => null // In case it was soft-deleted
                    ]
                );
            });

            Log::info('Face enrollment generated successfully', ['student_id' => $this->studentId]);

        } finally {
            @unlink($this->tmpPath);
        }
    }

    public function failed(\Throwable $exception): void
    {
        @unlink($this->tmpPath);
        Log::error('Face enrollment job failed: ' . $exception->getMessage(), [
            'student_id' => $this->studentId,
            'exception' => $exception
        ]);
    }
}

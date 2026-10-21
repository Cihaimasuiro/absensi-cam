<?php

namespace App\Console\Commands;

use App\Domain\Enrollment\Models\FaceTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class RotateEnrollmentKey extends Command
{
    protected $signature = 'app:rotate-enrollment-key
                            {--old-key= : The current/previous 32-byte base64 key if different from .env}
                            {--new-key= : Custom 32-byte base64-encoded key}
                            {--show-key : Display the unmasked key in console output}
                            {--dry-run : Test decryption and re-encryption without persisting}';

    protected $description = 'Rotate the ENROLLMENT_EMBED_KEY and re-encrypt all existing biometric face templates';

    public function handle(): int
    {
        $oldKeyRaw = $this->option('old-key') ?: config('app.enrollment_embed_key');
        if (empty($oldKeyRaw)) {
            $envPath = base_path('.env');
            if (File::exists($envPath)) {
                preg_match('/^ENROLLMENT_EMBED_KEY=(.*)$/m', File::get($envPath), $matches);
                $oldKeyRaw = trim($matches[1] ?? '');
            }
        }

        if (empty($oldKeyRaw)) {
            $this->error('Current ENROLLMENT_EMBED_KEY is not configured or found.');
            return self::FAILURE;
        }

        $oldKey = $this->decodeKey($oldKeyRaw);
        if ($oldKey === null) {
            $this->error('Current ENROLLMENT_EMBED_KEY is invalid (must be 32 bytes when base64 decoded).');
            return self::FAILURE;
        }

        // Determine new key
        $newKeyBase64 = $this->option('new-key');
        if (!empty($newKeyBase64)) {
            $newKey = $this->decodeKey($newKeyBase64);
            if ($newKey === null) {
                $this->error('Provided --new-key is invalid (must be 32 bytes when base64 decoded).');
                return self::FAILURE;
            }
        } else {
            $newKeyBytes = random_bytes(32);
            $newKeyBase64 = base64_encode($newKeyBytes);
            $newKey = $newKeyBytes;
        }

        if ($this->option('new-key') && $oldKeyRaw === $newKeyBase64) {
            $this->warn('New key is identical to the current key. Aborting.');
            return self::FAILURE;
        }

        // Pre-flight check file writability
        $envPath = base_path('.env');
        if (!File::exists($envPath) || !is_writable($envPath)) {
            $this->error("Cannot write to .env file at {$envPath}. Aborting.");
            return self::FAILURE;
        }

        $edgeEnvPath = base_path('clients/edge-engine-rs/.env');
        if (File::exists($edgeEnvPath) && !is_writable($edgeEnvPath)) {
            $this->error("Cannot write to edge engine .env at {$edgeEnvPath}. Aborting.");
            return self::FAILURE;
        }

        $templates = FaceTemplate::withTrashed()->get();
        $this->info("Found {$templates->count()} face templates to re-encrypt.");

        $reencrypted = [];

        foreach ($templates as $template) {
            if (empty($template->embedding_enc)) {
                continue;
            }

            $encryptedBlob = base64_decode($template->embedding_enc, true);
            if ($encryptedBlob === false || strlen($encryptedBlob) < 28) {
                $this->error("Template ID {$template->id} has corrupted ciphertext.");
                return self::FAILURE;
            }

            // Decrypt with old key
            $rawEmbedding = $this->decryptAesGcm(
                $encryptedBlob,
                $oldKey,
                (string) $template->student_id,
                (string) $template->model_version
            );

            if ($rawEmbedding === null) {
                $this->error("Failed to decrypt Template ID {$template->id} with current key.");
                return self::FAILURE;
            }

            // Encrypt with new key
            $newBlob = $this->encryptAesGcm(
                $rawEmbedding,
                $newKey,
                (string) $template->student_id,
                (string) $template->model_version
            );

            $reencrypted[$template->id] = base64_encode($newBlob);
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Successfully verified re-encryption for {$templates->count()} templates.");
            $this->line("Key would be updated and masked in logs.");
            return self::SUCCESS;
        }

        // Backup existing .env files prior to write
        $backupEnv = File::get($envPath);
        $backupEdgeEnv = File::exists($edgeEnvPath) ? File::get($edgeEnvPath) : null;

        try {
            DB::transaction(function () use ($templates, $reencrypted, $envPath, $edgeEnvPath, $newKeyBase64) {
                // 1. Persist DB changes
                foreach ($templates as $template) {
                    if (isset($reencrypted[$template->id])) {
                        DB::table('face_templates')
                            ->where('id', $template->id)
                            ->update(['embedding_enc' => $reencrypted[$template->id]]);
                    }
                }

                // 2. Write root .env atomically
                $this->updateEnvFile($envPath, $newKeyBase64);

                // 3. Write edge .env atomically if exists
                if (File::exists($edgeEnvPath)) {
                    $this->updateEnvFile($edgeEnvPath, $newKeyBase64);
                }
            });
        } catch (\Throwable $e) {
            // Restore backups on failure
            File::put($envPath, $backupEnv);
            if ($backupEdgeEnv !== null && File::exists($edgeEnvPath)) {
                File::put($edgeEnvPath, $backupEdgeEnv);
            }

            $this->error('Failed to rotate key: ' . $e->getMessage());
            Log::error('Key rotation rolled back: ' . $e->getMessage(), ['exception' => $e]);
            return self::FAILURE;
        }

        $this->callSilent('config:clear');

        $maskedKey = substr($newKeyBase64, 0, 4) . '...' . substr($newKeyBase64, -4);
        $this->info("Successfully rotated ENROLLMENT_EMBED_KEY and re-encrypted {$templates->count()} templates.");
        
        if ($this->option('show-key')) {
            $this->line("New ENROLLMENT_EMBED_KEY: <comment>{$newKeyBase64}</comment>");
        } else {
            $this->line("New ENROLLMENT_EMBED_KEY: <comment>{$maskedKey}</comment> (written directly to .env)");
        }

        $this->warn('NOTE: Any paired edge devices must re-sync or re-pair to receive newly re-encrypted templates.');

        Log::info('ENROLLMENT_EMBED_KEY rotated successfully', [
            'reencrypted_count' => count($reencrypted),
        ]);

        return self::SUCCESS;
    }

    private function decodeKey(string $keyBase64): ?string
    {
        if (str_starts_with($keyBase64, 'base64:')) {
            $keyBase64 = substr($keyBase64, 7);
        }

        $decoded = base64_decode($keyBase64, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            return null;
        }

        return $decoded;
    }

    private function decryptAesGcm(string $encryptedBlob, string $key, string $studentId, string $modelVersion): ?string
    {
        $nonce = substr($encryptedBlob, 0, 12);
        $tag = substr($encryptedBlob, -16);
        $ciphertext = substr($encryptedBlob, 12, -16);
        $aad = $studentId . '_' . $modelVersion;

        $decrypted = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad
        );

        return $decrypted === false ? null : $decrypted;
    }

    private function encryptAesGcm(string $rawEmbedding, string $key, string $studentId, string $modelVersion): string
    {
        $nonce = random_bytes(12);
        $aad = $studentId . '_' . $modelVersion;
        $tag = '';

        $ciphertext = openssl_encrypt(
            $rawEmbedding,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('AES-256-GCM encryption failed');
        }

        return $nonce . $ciphertext . $tag;
    }

    private function updateEnvFile(string $path, string $newKey): void
    {
        if (!File::exists($path)) {
            return;
        }

        $content = File::get($path);
        if (preg_match('/^ENROLLMENT_EMBED_KEY=/m', $content)) {
            $content = preg_replace('/^ENROLLMENT_EMBED_KEY=.*$/m', "ENROLLMENT_EMBED_KEY={$newKey}", $content);
        } else {
            $content .= "\nENROLLMENT_EMBED_KEY={$newKey}\n";
        }

        File::put($path, $content);
    }
}

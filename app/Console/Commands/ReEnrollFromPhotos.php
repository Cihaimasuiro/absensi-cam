<?php

namespace App\Console\Commands;

use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Enrollment\Services\BiometricDuplicateDetector;
use App\Domain\Enrollment\Services\FaceEncryptionService;
use App\Domain\Enrollment\Services\FaceExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReEnrollFromPhotos extends Command
{
    protected $signature = 'app:re-enroll-from-photos
                            {--force : Bypass confirmation prompt}
                            {--dry-run : Inspect photos and simulate extraction without updating database}
                            {--all : Force re-enrollment even if template already decrypts cleanly}
                            {--skip-duplicate-check : Allow saving even if duplicate face is detected}';

    protected $description = 'Safely re-extract and re-encrypt face embeddings from stored photos (recovery tool with safeguards)';

    public function handle(
        FaceEncryptionService $encryptionService,
        BiometricDuplicateDetector $duplicateDetector,
        FaceExtractor $faceExtractor
    ): int {
        if (!$this->option('force') && !$this->option('dry-run')) {
            if (!$this->confirm('This is a biometric recovery tool that will re-extract embeddings from stored photos. Continue?', false)) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        $templates = FaceTemplate::withTrashed()->get();
        $this->info("Found {$templates->count()} face templates in database.");

        $maxCursor = (int) DB::table('face_templates')->max('version_cursor');
        $success = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($templates as $template) {
            // Safeguard 1: Skip templates that can already be decrypted unless --all is specified
            if (!$this->option('all') && !empty($template->embedding_enc)) {
                try {
                    $existingRaw = $encryptionService->decrypt(
                        base64_decode($template->embedding_enc),
                        (string) $template->student_id,
                        (string) ($template->model_version ?: config('app.model_version'))
                    );

                    if (strlen($existingRaw) === 2048) {
                        $this->line("Skipping template ID {$template->id} (student: {$template->student_id}) - already decryptable. Use --all to force.");
                        $skipped++;
                        continue;
                    }
                } catch (\Throwable) {
                    // Not decryptable with current key; proceed with recovery re-enrollment
                }
            }

            $photoPath = $template->photo_path;
            if (!$photoPath || !Storage::disk('local')->exists($photoPath)) {
                $this->warn("Photo not found for template ID {$template->id} ({$photoPath})");
                $failed++;
                continue;
            }

            $fullPhotoPath = Storage::disk('local')->path($photoPath);

            try {
                $rawEmbedding = $faceExtractor->extract($fullPhotoPath);
            } catch (\Throwable $e) {
                $this->error("Extraction failed for template ID {$template->id}: " . $e->getMessage());
                $failed++;
                continue;
            }

            // Safeguard 2: Biometric 1:N duplicate face detection
            if (!$this->option('skip-duplicate-check')) {
                $duplicate = $duplicateDetector->detect($rawEmbedding, (string) $template->student_id);
                if ($duplicate !== null) {
                    $sim = round($duplicate['similarity'], 4);
                    $this->error("Aborting template ID {$template->id} due to duplicate face detection (matches student {$duplicate['matched_student_id']} with similarity {$sim}). Use --skip-duplicate-check to override.");
                    $failed++;
                    continue;
                }
            }

            $modelVersion = $template->model_version ?: config('app.model_version');
            $encryptedBlob = $encryptionService->encrypt($rawEmbedding, (string) $template->student_id, $modelVersion);
            $hash = hash('sha256', $rawEmbedding);

            // Safeguard 3: Verify immediate roundtrip decrypt before saving
            $decrypted = $encryptionService->decrypt($encryptedBlob, (string) $template->student_id, $modelVersion);
            if ($decrypted !== $rawEmbedding) {
                $this->error("Roundtrip verification failed for template ID {$template->id}");
                $failed++;
                continue;
            }

            // Safeguard 4: Dry-run simulation without touching database
            if ($this->option('dry-run')) {
                $this->info("[dry-run] Successfully extracted and verified template ID {$template->id} (student: {$template->student_id}). Database unchanged.");
                $success++;
                continue;
            }

            // Safeguard 5: Atomic database transaction & version_cursor bump for edge sync
            DB::transaction(function () use ($template, $encryptedBlob, $hash, $modelVersion, &$maxCursor) {
                $maxCursor++;
                DB::table('face_templates')
                    ->where('id', $template->id)
                    ->update([
                        'embedding_enc'  => base64_encode($encryptedBlob),
                        'embedding_hash' => $hash,
                        'model_version'  => $modelVersion,
                        'version_cursor' => $maxCursor,
                        'updated_at'     => now(),
                    ]);
            });

            $this->info("Template ID {$template->id} (student: {$template->student_id}) successfully re-enrolled (cursor: {$maxCursor}).");
            $success++;
        }

        if ($success > 0 && !$this->option('dry-run')) {
            Cache::forever('templates_hash_epoch', ((int) Cache::get('templates_hash_epoch', 1)) + 1);
        }

        $this->info("Re-enrollment summary: {$success} updated, {$skipped} skipped, {$failed} failed.");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

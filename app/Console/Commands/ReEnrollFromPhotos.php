<?php

namespace App\Console\Commands;

use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Enrollment\Services\FaceEncryptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ReEnrollFromPhotos extends Command
{
    protected $signature = 'app:re-enroll-from-photos';

    protected $description = 'Re-extract and re-encrypt face embeddings from stored enrollment photos using active ENROLLMENT_EMBED_KEY';

    public function handle(FaceEncryptionService $encryptionService): int
    {
        $templates = FaceTemplate::withTrashed()->get();
        $this->info("Found {$templates->count()} face templates in database.");

        $pythonBin = config('app.python_bin', 'python');
        $script = base_path('packages/face_core/extract.py');
        $detectorModel = base_path('packages/models/detector.onnx');
        $recognizerModel = base_path('packages/models/recognizer.onnx');

        $success = 0;
        $failed = 0;

        foreach ($templates as $template) {
            $photoPath = $template->photo_path;
            if (!$photoPath || !Storage::disk('local')->exists($photoPath)) {
                $this->warn("Photo not found for template ID {$template->id} ({$photoPath})");
                $failed++;
                continue;
            }

            $fullPhotoPath = Storage::disk('local')->path($photoPath);

            $result = Process::path(base_path())
                ->timeout(120)
                ->env(['PYTHONPATH' => base_path('packages')])
                ->run([
                    $pythonBin,
                    $script,
                    $detectorModel,
                    $recognizerModel,
                    $fullPhotoPath
                ]);

            if (!$result->successful()) {
                $this->error("Python extraction failed for template ID {$template->id}: " . $result->errorOutput());
                $failed++;
                continue;
            }

            $output = json_decode($result->output(), true);
            if (!$output || !isset($output['success']) || !$output['success'] || !isset($output['embedding_b64'])) {
                $this->error("Invalid extractor output for template ID {$template->id}");
                $failed++;
                continue;
            }

            $rawEmbedding = base64_decode($output['embedding_b64']);
            if (strlen($rawEmbedding) !== 2048) {
                $this->error("Raw embedding for template ID {$template->id} is not 2048 bytes.");
                $failed++;
                continue;
            }

            $modelVersion = $template->model_version ?: config('app.model_version');
            $encryptedBlob = $encryptionService->encrypt($rawEmbedding, (string) $template->student_id, $modelVersion);
            $hash = hash('sha256', $rawEmbedding);

            // Verify immediate roundtrip decrypt before saving
            $decrypted = $encryptionService->decrypt($encryptedBlob, (string) $template->student_id, $modelVersion);
            if ($decrypted !== $rawEmbedding) {
                $this->error("Roundtrip verification failed for template ID {$template->id}");
                $failed++;
                continue;
            }

            DB::table('face_templates')
                ->where('id', $template->id)
                ->update([
                    'embedding_enc'  => base64_encode($encryptedBlob),
                    'embedding_hash' => $hash,
                    'model_version'  => $modelVersion,
                    'updated_at'     => now(),
                ]);

            $this->info("Template ID {$template->id} (student: {$template->student_id}) successfully re-enrolled and encrypted.");
            $success++;
        }

        $this->info("Re-enrollment complete: {$success} succeeded, {$failed} failed.");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

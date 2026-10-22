<?php

namespace App\Console\Commands;

use App\Domain\Enrollment\Models\FaceTemplate;
use App\Jobs\GenerateFaceEmbedding;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Signature('edge:update-model {model_version} {download_url} {checksum}')]
#[Description('Trigger model OTA update on all edge devices and queue vector re-extraction.')]
class PushModelUpdate extends Command
{
    /**
     * Jalankan console command.
     */
    public function handle()
    {
        $modelVersion = $this->argument('model_version');
        $downloadUrl = $this->argument('download_url');
        $checksum = $this->argument('checksum');

        if (!Str::startsWith($downloadUrl, 'https://')) {
            $this->error("Download URL must use HTTPS.");
            return 1;
        }

        if (strlen($checksum) !== 64) {
            $this->error("Checksum must be a valid 64-character SHA-256 string.");
            return 1;
        }

        $this->info("Starting Model OTA Update to: {$modelVersion}");

        // 1. Dispatch ekstraksi ulang untuk semua siswa yang memiliki foto tersimpan
        $this->info("Queueing vector re-extraction for enrolled students...");
        $templates = FaceTemplate::whereNotNull('photo_path')->get();
        $queued = 0;
        
        foreach ($templates as $template) {
            if (!Storage::disk('local')->exists($template->photo_path)) {
                $this->warn("Photo missing for student {$template->student_id} at {$template->photo_path}");
                continue;
            }

            // Buat salinan tmp untuk diproses oleh binary
            $tmpFilename = "reextract_{$template->student_id}_" . Str::uuid() . "." . pathinfo($template->photo_path, PATHINFO_EXTENSION);
            Storage::disk('local')->copy($template->photo_path, "tmp/{$tmpFilename}");
            $tmpPath = Storage::disk('local')->path("tmp/{$tmpFilename}");

            GenerateFaceEmbedding::dispatch(
                $template->student_id, 
                $tmpPath, 
                $template->photo_path, 
                true, // delete after
                $modelVersion
            )->onQueue('enrollments');

            $queued++;
        }
        $this->info("Queued {$queued} students for re-extraction.");

        // 2. Beri tahu perangkat edge untuk mengunduh model baru
        // Catatan: Untuk push sungguhan, kita butuh plain token yang tidak kita simpan (hanya hash yang disimpan untuk Sanctum).
        // Implementasi produksi akan push event ke MQTT broker, atau
        // perangkat edge akan melakukan polling ke endpoint /api/v1/device/update secara berkala.
        // Untuk saat ini kami mencatat tindakan manual yang diperlukan.
        $this->info("To complete the update, devices must either pull from an update endpoint, or you must push to them via MQTT/WS.");
        $this->info("Command completed successfully. Vectors will be re-extracted in the background.");
        return 0;
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Enrollment\Services\FaceEncryptionService;
use App\Domain\Student\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ReEnrollFromPhotosTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_already_decryptable_templates_by_default(): void
    {
        $keyRaw = base64_encode(random_bytes(32));
        Config::set('app.enrollment_embed_key', $keyRaw);

        $student = Student::factory()->create();
        $encryptionService = app(FaceEncryptionService::class);
        $rawVector = str_repeat('B', 2048);

        $encBlob = $encryptionService->encrypt($rawVector, $student->id, '3d9f1f77896fb3d1');

        $template = FaceTemplate::create([
            'student_id'     => $student->id,
            'embedding_enc'  => base64_encode($encBlob),
            'model_version'  => '3d9f1f77896fb3d1',
            'embedding_hash' => hash('sha256', $rawVector),
            'photo_path'     => 'faces/nonexistent.jpg',
        ]);

        $initialUpdated = $template->updated_at;

        // Running without --all must skip the template because it already decrypts cleanly
        $this->artisan('app:re-enroll-from-photos', [
            '--force' => true,
        ])
        ->expectsOutputToContain("Skipping template ID {$template->id}")
        ->expectsOutputToContain('0 updated, 1 skipped, 0 failed')
        ->assertSuccessful();

        $template->refresh();
        $this->assertEquals($initialUpdated->timestamp, $template->updated_at->timestamp);
    }

    public function test_confirmation_aborts_when_declined(): void
    {
        $this->artisan('app:re-enroll-from-photos')
            ->expectsConfirmation('This is a biometric recovery tool that will re-extract embeddings from stored photos. Continue?', 'no')
            ->expectsOutputToContain('Operation cancelled.')
            ->assertSuccessful();
    }

    public function test_dry_run_skips_database_write(): void
    {
        $keyRaw = base64_encode(random_bytes(32));
        Config::set('app.enrollment_embed_key', $keyRaw);

        $student = Student::factory()->create();
        $encryptionService = app(FaceEncryptionService::class);
        $rawVector = str_repeat('C', 2048);

        $encBlob = $encryptionService->encrypt($rawVector, $student->id, '3d9f1f77896fb3d1');

        $template = FaceTemplate::create([
            'student_id'     => $student->id,
            'embedding_enc'  => base64_encode($encBlob),
            'model_version'  => '3d9f1f77896fb3d1',
            'embedding_hash' => hash('sha256', $rawVector),
        ]);

        $this->artisan('app:re-enroll-from-photos', [
            '--dry-run' => true,
        ])
        ->expectsOutputToContain("Skipping template ID {$template->id}")
        ->assertSuccessful();
    }
}

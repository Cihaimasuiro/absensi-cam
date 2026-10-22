<?php

namespace Tests\Feature;

use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Enrollment\Services\FaceEncryptionService;
use App\Domain\Student\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
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

    public function test_successful_re_enroll_updates_database_and_bumps_cursor(): void
    {
        $keyRaw = base64_encode(random_bytes(32));
        Config::set('app.enrollment_embed_key', $keyRaw);

        Storage::fake('local');
        Storage::disk('local')->put('faces/student_test.jpg', 'fake-image-content');

        $student = Student::factory()->create();
        $template = FaceTemplate::create([
            'student_id'     => $student->id,
            'embedding_enc'  => base64_encode('corrupted_ciphertext_that_cannot_decrypt'),
            'model_version'  => '3d9f1f77896fb3d1',
            'embedding_hash' => 'old_hash',
            'photo_path'     => 'faces/student_test.jpg',
        ]);

        $initialCursor = $template->version_cursor;
        $mockEmbedding = str_repeat('Z', 2048);

        Process::fake([
            '*' => Process::result(json_encode([
                'success'       => true,
                'embedding_b64' => base64_encode($mockEmbedding),
            ])),
        ]);

        $this->artisan('app:re-enroll-from-photos', [
            '--force' => true,
        ])
        ->expectsOutputToContain("Template ID {$template->id} (student: {$student->id}) successfully re-enrolled")
        ->expectsOutputToContain('1 updated, 0 skipped, 0 failed')
        ->assertSuccessful();

        $template->refresh();
        $this->assertGreaterThan($initialCursor, $template->version_cursor);

        // Verify template now decrypts with active key
        $encryptionService = app(FaceEncryptionService::class);
        $decrypted = $encryptionService->decrypt(
            base64_decode($template->embedding_enc),
            $student->id,
            '3d9f1f77896fb3d1'
        );
        $this->assertEquals($mockEmbedding, $decrypted);
        $this->assertGreaterThan(1, (int) Cache::get('templates_hash_epoch', 1));
    }

    public function test_duplicate_check_aborts_even_with_force_unless_skip_flag_passed(): void
    {
        $keyRaw = base64_encode(random_bytes(32));
        Config::set('app.enrollment_embed_key', $keyRaw);

        Storage::fake('local');
        Storage::disk('local')->put('faces/student2.jpg', 'fake-image-content');

        $encryptionService = app(FaceEncryptionService::class);
        $duplicateVector = str_repeat('D', 2048);

        // Student 1 already has this vector enrolled
        $student1 = Student::factory()->create();
        FaceTemplate::create([
            'student_id'     => $student1->id,
            'embedding_enc'  => base64_encode($encryptionService->encrypt($duplicateVector, $student1->id, '3d9f1f77896fb3d1')),
            'model_version'  => '3d9f1f77896fb3d1',
            'embedding_hash' => hash('sha256', $duplicateVector),
            'photo_path'     => 'faces/student1.jpg',
        ]);

        // Student 2 has undecryptable template
        $student2 = Student::factory()->create(['school_id' => $student1->school_id]);
        $template2 = FaceTemplate::create([
            'student_id'     => $student2->id,
            'embedding_enc'  => base64_encode('corrupted'),
            'model_version'  => '3d9f1f77896fb3d1',
            'embedding_hash' => 'old_hash',
            'photo_path'     => 'faces/student2.jpg',
        ]);

        // Mock process to extract identical vector
        Process::fake([
            '*' => Process::result(json_encode([
                'success'       => true,
                'embedding_b64' => base64_encode($duplicateVector),
            ])),
        ]);

        // With --force only, duplicate detection MUST abort to prevent unintended collision
        $this->artisan('app:re-enroll-from-photos', [
            '--force' => true,
        ])
        ->expectsOutputToContain('Aborting template ID ' . $template2->id . ' due to duplicate face detection')
        ->assertFailed();

        // With --skip-duplicate-check, it succeeds
        $this->artisan('app:re-enroll-from-photos', [
            '--force'                => true,
            '--skip-duplicate-check' => true,
        ])
        ->expectsOutputToContain('1 updated, 1 skipped, 0 failed')
        ->assertSuccessful();
    }
}

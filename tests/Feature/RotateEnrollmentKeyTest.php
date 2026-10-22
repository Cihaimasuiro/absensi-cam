<?php

namespace Tests\Feature;

use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Enrollment\Services\FaceEncryptionService;
use App\Domain\Student\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RotateEnrollmentKeyTest extends TestCase
{
    use RefreshDatabase;

    private string $tempEnvPath;
    private string $tempEdgeEnvPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempEnvPath = tempnam(sys_get_temp_dir(), 'env_root_');
        $this->tempEdgeEnvPath = tempnam(sys_get_temp_dir(), 'env_edge_');

        File::put($this->tempEnvPath, "APP_NAME=Test\nENROLLMENT_EMBED_KEY=\n");
        File::put($this->tempEdgeEnvPath, "SMART_ABSENSI_URL=http://localhost\nENROLLMENT_EMBED_KEY=\n");
    }

    protected function tearDown(): void
    {
        if (File::exists($this->tempEnvPath)) {
            File::delete($this->tempEnvPath);
        }
        if (File::exists($this->tempEdgeEnvPath)) {
            File::delete($this->tempEdgeEnvPath);
        }

        parent::tearDown();
    }

    public function test_can_rotate_enrollment_key_and_reencrypt_face_templates(): void
    {
        $oldKeyRaw = base64_encode(random_bytes(32));
        $newKeyRaw = base64_encode(random_bytes(32));

        Config::set('app.enrollment_embed_key', $oldKeyRaw);

        $student = Student::factory()->create();
        $encryptionService = app(FaceEncryptionService::class);
        $rawVector = str_repeat('A', 2048);

        $encBlob = $encryptionService->encrypt($rawVector, $student->id, '3d9f1f77896fb3d1');

        $template = FaceTemplate::create([
            'student_id'     => $student->id,
            'embedding_enc'  => base64_encode($encBlob),
            'model_version'  => '3d9f1f77896fb3d1',
            'embedding_hash' => hash('sha256', $rawVector),
        ]);

        // Run dry-run using isolated temporary env files
        $this->artisan('app:rotate-enrollment-key', [
            '--new-key'       => $newKeyRaw,
            '--env-path'      => $this->tempEnvPath,
            '--edge-env-path' => $this->tempEdgeEnvPath,
            '--dry-run'       => true,
        ])->assertSuccessful();

        // Run actual rotation using isolated temporary env files
        $this->artisan('app:rotate-enrollment-key', [
            '--new-key'       => $newKeyRaw,
            '--env-path'      => $this->tempEnvPath,
            '--edge-env-path' => $this->tempEdgeEnvPath,
        ])->assertSuccessful();

        // Verify the temporary env file was updated with the new key
        $this->assertStringContainsString("ENROLLMENT_EMBED_KEY={$newKeyRaw}", File::get($this->tempEnvPath));
        $this->assertStringContainsString("ENROLLMENT_EMBED_KEY={$newKeyRaw}", File::get($this->tempEdgeEnvPath));

        // Now set the app config to the new key and verify decryption succeeds
        Config::set('app.enrollment_embed_key', $newKeyRaw);
        $template->refresh();

        $decrypted = $encryptionService->decrypt(
            base64_decode($template->embedding_enc),
            $student->id,
            '3d9f1f77896fb3d1'
        );

        $this->assertEquals($rawVector, $decrypted);
        $this->assertGreaterThan(0, $template->version_cursor);
    }

    public function test_rotation_fails_gracefully_when_env_file_missing(): void
    {
        $nonExistentEnv = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing_env_' . uniqid();

        $this->artisan('app:rotate-enrollment-key', [
            '--env-path' => $nonExistentEnv,
        ])
        ->expectsOutputToContain('Target .env file does not exist')
        ->assertFailed();
    }

    public function test_force_sync_bumps_version_cursor_for_edge_devices(): void
    {
        $student = Student::factory()->create();
        $template = FaceTemplate::create([
            'student_id'     => $student->id,
            'embedding_enc'  => base64_encode(str_repeat('X', 2048)),
            'model_version'  => '3d9f1f77896fb3d1',
            'embedding_hash' => hash('sha256', 'dummy'),
            'version_cursor' => 5,
        ]);

        $initialCursor = $template->version_cursor;

        $this->artisan('app:rotate-enrollment-key', [
            '--force-sync' => true,
        ])
        ->expectsOutputToContain('Successfully bumped version cursors')
        ->assertSuccessful();

        $template->refresh();
        $this->assertGreaterThan($initialCursor, $template->version_cursor);
    }
}


<?php

namespace Tests\Feature;

use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Enrollment\Services\FaceEncryptionService;
use App\Domain\Student\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class RotateEnrollmentKeyTest extends TestCase
{
    use RefreshDatabase;

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

        // Run dry-run
        $this->artisan('app:rotate-enrollment-key', [
            '--new-key' => $newKeyRaw,
            '--dry-run' => true,
        ])->assertSuccessful();

        // Run actual rotation
        $this->artisan('app:rotate-enrollment-key', [
            '--new-key' => $newKeyRaw,
        ])->assertSuccessful();

        // Now set the app config to the new key and verify decryption succeeds
        Config::set('app.enrollment_embed_key', $newKeyRaw);
        $template->refresh();

        $decrypted = $encryptionService->decrypt(
            base64_decode($template->embedding_enc),
            $student->id,
            '3d9f1f77896fb3d1'
        );

        $this->assertEquals($rawVector, $decrypted);
    }
}

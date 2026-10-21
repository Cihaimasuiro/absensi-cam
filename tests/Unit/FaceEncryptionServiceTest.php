<?php

namespace Tests\Unit;

use App\Domain\Enrollment\Services\FaceEncryptionService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class FaceEncryptionServiceTest extends TestCase
{
    public function test_can_encrypt_and_decrypt_with_standard_base64_key(): void
    {
        // 32-byte raw key encoded to base64
        $rawKey = random_bytes(32);
        Config::set('app.enrollment_embed_key', base64_encode($rawKey));

        $service = new FaceEncryptionService();
        $payload = 'arcface_512_raw_embedding_binary_data';
        $studentId = 'student-uuid-1234';
        $modelVersion = 'v0.2.0';

        $encrypted = $service->encrypt($payload, $studentId, $modelVersion);
        $this->assertNotEmpty($encrypted);
        $this->assertNotEquals($payload, $encrypted);

        $decrypted = $service->decrypt($encrypted, $studentId, $modelVersion);
        $this->assertEquals($payload, $decrypted);
    }

    public function test_can_encrypt_and_decrypt_with_base64_prefixed_key(): void
    {
        // key:generate --show format (base64:... prefix)
        $rawKey = random_bytes(32);
        Config::set('app.enrollment_embed_key', 'base64:' . base64_encode($rawKey));

        $service = new FaceEncryptionService();
        $payload = 'arcface_512_raw_embedding_binary_data';
        $studentId = 'student-uuid-5678';
        $modelVersion = 'v0.2.0';

        $encrypted = $service->encrypt($payload, $studentId, $modelVersion);
        $this->assertNotEmpty($encrypted);

        $decrypted = $service->decrypt($encrypted, $studentId, $modelVersion);
        $this->assertEquals($payload, $decrypted);
    }

    public function test_throws_exception_if_key_is_not_32_bytes(): void
    {
        Config::set('app.enrollment_embed_key', base64_encode('too-short'));

        $service = new FaceEncryptionService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ENROLLMENT_EMBED_KEY must be exactly 32 bytes when decoded');

        $service->encrypt('data', 'id', 'model');
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Enrollment\Exceptions\DuplicateFaceException;
use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Enrollment\Services\FaceEncryptionService;
use App\Domain\School\Models\School;
use App\Domain\Student\Models\Student;
use App\Jobs\GenerateFaceEmbedding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BiometricDuplicateDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function giveConsent(Student $student): void
    {
        $user = \App\Domain\User\Models\User::first() ?? \App\Domain\User\Models\User::factory()->create();
        $student->consents()->create([
            'given_at'     => now(),
            'text_version' => 'v1.0',
            'recorded_by'  => $user->id,
        ]);
    }

    private function generateEmbeddingVector(float $val): string
    {
        $floats = array_fill(0, 512, $val);
        return pack('g*', ...$floats);
    }

    public function test_duplicate_face_enrollment_is_rejected_above_threshold(): void
    {
        $school = School::factory()->create();
        $student1 = Student::factory()->create(['school_id' => $school->id, 'code' => 'STU-1']);
        $student2 = Student::factory()->create(['school_id' => $school->id, 'code' => 'STU-2']);
        $this->giveConsent($student1);
        $this->giveConsent($student2);

        $encryptionService = app(FaceEncryptionService::class);
        $rawVector = $this->generateEmbeddingVector(0.5);

        // Siswa 1 telah terdaftar dengan template ini
        $encBlob = $encryptionService->encrypt($rawVector, $student1->id, '3d9f1f77896fb3d1');
        FaceTemplate::create([
            'student_id' => $student1->id,
            'embedding_enc' => base64_encode($encBlob),
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => hash('sha256', $rawVector),
        ]);

        // Siswa 2 mencoba mendaftar dengan wajah yang sama (kesamaan 1.0 >= 0.75)
        $tmpPath = sys_get_temp_dir() . '/dup_test_photo.jpg';
        file_put_contents($tmpPath, 'dummy image data');

        Process::fake([
            '*extract.py*' => Process::result(json_encode([
                'success' => true,
                'embedding_b64' => base64_encode($rawVector),
            ])),
        ]);

        $job = new GenerateFaceEmbedding(
            studentId: $student2->id,
            tmpPath: $tmpPath,
            deleteAfter: true,
            modelVersion: '3d9f1f77896fb3d1',
            allowDuplicate: false
        );

        $this->expectException(DuplicateFaceException::class);
        $this->expectExceptionMessage('Wajah ini terdeteksi sangat mirip dengan anggota lain yang sudah terdaftar. Hubungi administrator jika ini adalah saudara kembar.');

        try {
            $job->handle();
        } finally {
            $this->assertDatabaseMissing('face_templates', [
                'student_id' => $student2->id,
            ]);
        }
    }

    public function test_duplicate_face_enrollment_allowed_with_bypass_flag(): void
    {
        $school = School::factory()->create();
        $student1 = Student::factory()->create(['school_id' => $school->id, 'code' => 'STU-1']);
        $student2 = Student::factory()->create(['school_id' => $school->id, 'code' => 'STU-2']);
        $this->giveConsent($student1);
        $this->giveConsent($student2);

        $encryptionService = app(FaceEncryptionService::class);
        $rawVector = $this->generateEmbeddingVector(0.5);

        $encBlob = $encryptionService->encrypt($rawVector, $student1->id, '3d9f1f77896fb3d1');
        FaceTemplate::create([
            'student_id' => $student1->id,
            'embedding_enc' => base64_encode($encBlob),
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => hash('sha256', $rawVector),
        ]);

        $tmpPath = sys_get_temp_dir() . '/dup_allow_photo.jpg';
        file_put_contents($tmpPath, 'dummy image data');

        Process::fake([
            '*extract.py*' => Process::result(json_encode([
                'success' => true,
                'embedding_b64' => base64_encode($rawVector),
            ])),
        ]);

        $job = new GenerateFaceEmbedding(
            studentId: $student2->id,
            tmpPath: $tmpPath,
            deleteAfter: true,
            modelVersion: '3d9f1f77896fb3d1',
            allowDuplicate: true // Bypass diizinkan oleh admin
        );

        $job->handle();

        $this->assertDatabaseHas('face_templates', [
            'student_id' => $student2->id,
        ]);
    }

    public function test_duplicate_face_ignores_soft_deleted_students(): void
    {
        $school = School::factory()->create();
        $student1 = Student::factory()->create(['school_id' => $school->id, 'code' => 'STU-1']);
        $student2 = Student::factory()->create(['school_id' => $school->id, 'code' => 'STU-2']);
        $this->giveConsent($student1);
        $this->giveConsent($student2);

        $encryptionService = app(FaceEncryptionService::class);
        $rawVector = $this->generateEmbeddingVector(0.5);

        $encBlob = $encryptionService->encrypt($rawVector, $student1->id, '3d9f1f77896fb3d1');
        FaceTemplate::create([
            'student_id' => $student1->id,
            'embedding_enc' => base64_encode($encBlob),
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => hash('sha256', $rawVector),
        ]);

        // Siswa 1 dihapus (soft-delete)
        $student1->delete();

        $tmpPath = sys_get_temp_dir() . '/dup_deleted_photo.jpg';
        file_put_contents($tmpPath, 'dummy image data');

        Process::fake([
            '*extract.py*' => Process::result(json_encode([
                'success' => true,
                'embedding_b64' => base64_encode($rawVector),
            ])),
        ]);

        // Siswa 2 mendaftar dengan wajah siswa 1 yang sudah terhapus -> harus BERHASIL tanpa error
        $job = new GenerateFaceEmbedding(
            studentId: $student2->id,
            tmpPath: $tmpPath,
            deleteAfter: true,
            modelVersion: '3d9f1f77896fb3d1',
            allowDuplicate: false
        );

        $job->handle();

        $this->assertDatabaseHas('face_templates', [
            'student_id' => $student2->id,
        ]);
    }

    public function test_artisan_audit_duplicates_detects_matching_pairs(): void
    {
        $school = School::factory()->create();
        $student1 = Student::factory()->create(['school_id' => $school->id, 'name' => 'Budi A', 'code' => 'STU-A']);
        $student2 = Student::factory()->create(['school_id' => $school->id, 'name' => 'Budi B', 'code' => 'STU-B']);

        $encryptionService = app(FaceEncryptionService::class);
        $rawVector = $this->generateEmbeddingVector(0.5);

        FaceTemplate::create([
            'student_id' => $student1->id,
            'embedding_enc' => base64_encode($encryptionService->encrypt($rawVector, $student1->id, '3d9f1f77896fb3d1')),
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => hash('sha256', $rawVector),
        ]);

        FaceTemplate::create([
            'student_id' => $student2->id,
            'embedding_enc' => base64_encode($encryptionService->encrypt($rawVector, $student2->id, '3d9f1f77896fb3d1')),
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => hash('sha256', $rawVector),
        ]);

        $this->artisan('biometric:audit-duplicates')
            ->expectsOutputToContain('Ditemukan 1 pasang kandidat duplikasi wajah:')
            ->expectsOutputToContain('Budi A (STU-A)')
            ->expectsOutputToContain('Budi B (STU-B)')
            ->assertExitCode(0);
    }
}

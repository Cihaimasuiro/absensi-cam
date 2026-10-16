<?php

namespace Tests\Feature;

use App\Domain\Device\Models\Device;
use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\School\Models\School;
use App\Domain\Student\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentDeletionTombstoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_soft_delete_generates_tombstone_with_bumped_cursor_and_building_fallback(): void
    {
        $school = School::factory()->create();

        // Perangkat tanpa building_id (gate/global sekolah)
        $device = Device::factory()->create([
            'building_id' => null,
            'model_version' => '3d9f1f77896fb3d1',
        ]);

        $student = Student::factory()->create([
            'school_id' => $school->id,
            'name' => 'Bintang Siswa',
            'code' => 'TEST-001',
        ]);

        $template = FaceTemplate::create([
            'student_id' => $student->id,
            'embedding_enc' => 'ciphertext_base64',
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash_test_123',
        ]);

        $initialCursor = $template->version_cursor;
        $this->assertNotNull($initialCursor);

        // 1. Verifikasi template aktif dikirimkan sebagai op=upsert ke perangkat tanpa building_id
        Sanctum::actingAs($device, ['device']);
        $resActive = $this->getJson('/api/v1/templates?cursor=0');
        $resActive->assertStatus(200);
        $this->assertCount(1, $resActive->json('items'));
        $this->assertEquals('upsert', $resActive->json('items.0.op'));
        $this->assertEquals($student->id, $resActive->json('items.0.student_id'));

        // 2. Hapus siswa (soft-delete)
        $student->delete();

        // 3. Verifikasi siswa dan face template telah di-soft-delete
        $this->assertSoftDeleted('students', ['id' => $student->id]);
        $this->assertSoftDeleted('face_templates', ['id' => $template->id]);

        // 4. Verifikasi version_cursor face template telah di-bump
        $deletedTemplate = FaceTemplate::withTrashed()->find($template->id);
        $this->assertGreaterThan($initialCursor, $deletedTemplate->version_cursor);

        // 5. Verifikasi delta sync mengirimkan op=delete (tombstone)
        $resDelta = $this->getJson("/api/v1/templates?cursor={$initialCursor}");
        $resDelta->assertStatus(200);
        $this->assertCount(1, $resDelta->json('items'));
        $this->assertEquals('delete', $resDelta->json('items.0.op'));
        $this->assertEquals($student->id, $resDelta->json('items.0.student_id'));
    }
}

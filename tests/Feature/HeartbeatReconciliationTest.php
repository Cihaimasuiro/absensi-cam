<?php

namespace Tests\Feature;

use App\Domain\Device\Models\Device;
use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\School\Models\School;
use App\Domain\Student\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HeartbeatReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_returns_reconcile_action_on_hash_mismatch(): void
    {
        $school = School::factory()->create();

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

        Sanctum::actingAs($device, ['device']);

        // 1. Expected hash calculation
        $expectedCombined = $student->id . ':' . ($template->version_cursor ?? 1) . "\n";
        $expectedHash = hash('sha256', $expectedCombined);

        $basePayload = [
            'fw_version' => '1.0.0',
            'model_version' => '3d9f1f77896fb3d1',
            'cpu_temp' => 45.5,
            'ram_free_mb' => 512,
            'disk_free_mb' => 2048,
            'fps' => 15.0,
            'outbox_len' => 0,
            'serial_ok' => true,
        ];

        // 2. Heartbeat dengan matching hash -> action 'none'
        $responseMatch = $this->postJson('/api/v1/devices/heartbeat', array_merge($basePayload, [
            'templates_count' => 1,
            'templates_hash' => $expectedHash,
        ]));

        $responseMatch->assertStatus(200);
        $responseMatch->assertJson([
            'ok' => true,
            'action' => 'none',
            'server_hash' => $expectedHash,
        ]);

        // 3. Heartbeat dengan stale/mismatched hash -> action 'reconcile_templates'
        $staleHash = hash('sha256', "stale_hash_data\n");
        $responseMismatch = $this->postJson('/api/v1/devices/heartbeat', array_merge($basePayload, [
            'templates_count' => 2,
            'templates_hash' => $staleHash,
        ]));

        $responseMismatch->assertStatus(200);
        $responseMismatch->assertJson([
            'ok' => true,
            'action' => 'reconcile_templates',
            'server_hash' => $expectedHash,
        ]);

        // 4. Verifikasi snapshot mengembalikan ID aktif
        $snapshotRes = $this->getJson('/api/v1/templates/snapshot');
        $snapshotRes->assertStatus(200);
        $snapshotRes->assertJson([
            'count' => 1,
            'valid_student_ids' => [$student->id],
        ]);
    }

    public function test_snapshot_excludes_soft_deleted_students(): void
    {
        $school = School::factory()->create();

        $device = Device::factory()->create([
            'building_id' => null,
            'model_version' => '3d9f1f77896fb3d1',
        ]);

        $student1 = Student::factory()->create(['school_id' => $school->id, 'code' => 'STU-1']);
        $student2 = Student::factory()->create(['school_id' => $school->id, 'code' => 'STU-2']);

        FaceTemplate::create([
            'student_id' => $student1->id,
            'embedding_enc' => 'ciphertext_1',
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash_1',
        ]);

        FaceTemplate::create([
            'student_id' => $student2->id,
            'embedding_enc' => 'ciphertext_2',
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash_2',
        ]);

        Sanctum::actingAs($device, ['device']);

        $resBefore = $this->getJson('/api/v1/templates/snapshot');
        $resBefore->assertStatus(200);
        $this->assertEquals(2, $resBefore->json('count'));
        $this->assertContains($student1->id, $resBefore->json('valid_student_ids'));
        $this->assertContains($student2->id, $resBefore->json('valid_student_ids'));

        // Soft delete student 2
        $student2->delete();

        $resAfter = $this->getJson('/api/v1/templates/snapshot');
        $resAfter->assertStatus(200);
        $this->assertEquals(1, $resAfter->json('count'));
        $this->assertContains($student1->id, $resAfter->json('valid_student_ids'));
        $this->assertNotContains($student2->id, $resAfter->json('valid_student_ids'));
    }
}

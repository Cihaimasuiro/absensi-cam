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

    public function test_template_change_flips_action_to_reconcile_templates(): void
    {
        $school = School::factory()->create();
        $building = \App\Domain\School\Models\Building::factory()->create(['school_id' => $school->id]);

        $device = Device::factory()->create([
            'building_id' => $building->id,
            'model_version' => '3d9f1f77896fb3d1',
        ]);

        $student1 = Student::factory()->create(['school_id' => $school->id, 'code' => 'STU-001']);
        $template1 = FaceTemplate::create([
            'student_id' => $student1->id,
            'embedding_enc' => 'ciphertext_1',
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash_1',
        ]);

        Sanctum::actingAs($device, ['device']);

        $basePayload = [
            'fw_version' => '1.0.0',
            'model_version' => '3d9f1f77896fb3d1',
            'cpu_temp' => 45.0,
            'ram_free_mb' => 512,
            'disk_free_mb' => 2048,
            'fps' => 15.0,
            'outbox_len' => 0,
            'serial_ok' => true,
        ];

        // 1. Initial heartbeat with exact hash matches -> action is 'none'
        $expectedHash1 = hash('sha256', $student1->id . ':' . ($template1->version_cursor ?? 1) . "\n");

        $res1 = $this->postJson('/api/v1/devices/heartbeat', array_merge($basePayload, [
            'templates_count' => 1,
            'templates_hash' => $expectedHash1,
        ]));

        $res1->assertStatus(200);
        $res1->assertJson([
            'ok' => true,
            'action' => 'none',
            'server_hash' => $expectedHash1,
        ]);

        // 2. Add a new template (student 2 enrolled)
        $student2 = Student::factory()->create(['school_id' => $school->id, 'code' => 'STU-002']);
        $template2 = FaceTemplate::create([
            'student_id' => $student2->id,
            'embedding_enc' => 'ciphertext_2',
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash_2',
        ]);

        // 3. Device sends heartbeat still reporting the old hash ($expectedHash1)
        // Must flip action to 'reconcile_templates' immediately!
        $res2 = $this->postJson('/api/v1/devices/heartbeat', array_merge($basePayload, [
            'templates_count' => 1,
            'templates_hash' => $expectedHash1,
        ]));

        $res2->assertStatus(200);
        $res2->assertJson([
            'ok' => true,
            'action' => 'reconcile_templates',
        ]);
        $this->assertNotEquals($expectedHash1, $res2->json('server_hash'));

        // 4. Verify server hash matches the new 2-template sorted hash
        $sorted = collect([
            ['id' => $student1->id, 'cursor' => $template1->version_cursor],
            ['id' => $student2->id, 'cursor' => $template2->version_cursor],
        ])->sortBy('id');
        $combinedNew = '';
        foreach ($sorted as $s) {
            $combinedNew .= $s['id'] . ':' . $s['cursor'] . "\n";
        }
        $expectedHash2 = hash('sha256', $combinedNew);
        $this->assertEquals($expectedHash2, $res2->json('server_hash'));

        // 5. Soft-delete template 2 -> next heartbeat with $expectedHash2 must flip action back to reconcile_templates!
        $template2->delete();

        $res3 = $this->postJson('/api/v1/devices/heartbeat', array_merge($basePayload, [
            'templates_count' => 2,
            'templates_hash' => $expectedHash2,
        ]));

        $res3->assertStatus(200);
        $res3->assertJson([
            'ok' => true,
            'action' => 'reconcile_templates',
            'server_hash' => $expectedHash1,
        ]);
    }
}

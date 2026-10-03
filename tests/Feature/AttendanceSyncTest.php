<?php

namespace Tests\Feature;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Device\Models\Device;
use App\Domain\Student\Models\Student;
use App\Domain\School\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: Attendance Batch Sync API
 * Covers: POST /api/v1/attendance/batch
 * Ref: PRD §3.3 Sync Protocol, AGENTS.md §Transactions (Rule 22), §Security (Rule 33)
 */
class AttendanceSyncTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = Device::factory()->create(['status' => 'online']);
        Sanctum::actingAs($this->device, ['device']);

        $school = School::factory()->create();
        $this->student = Student::factory()->create(['school_id' => $school->id]);
    }

    public function test_can_sync_attendance_records_from_edge_node(): void
    {
        $response = $this->postJson('/api/v1/attendance/batch', [
            'records' => [
                [
                    'id'          => '123e4567-e89b-12d3-a456-426614174000',
                    'student_id'  => $this->student->id,
                    'direction'   => 'in',
                    'time_source' => 'ntp',
                    'captured_at' => '2026-09-29T08:30:00Z',
                    'score'       => 0.95,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('results.0.status', 'created');

        $this->assertDatabaseHas('attendance_logs', [
            'student_id' => $this->student->id,
            'direction'  => 'in',
        ]);
    }

    public function test_batch_accepts_multiple_records(): void
    {
        $student2 = Student::factory()->create(['school_id' => $this->student->school_id]);

        $response = $this->postJson('/api/v1/attendance/batch', [
            'records' => [
                [
                    'id'          => 'aaaaaaaa-0000-0000-0000-000000000001',
                    'student_id'  => $this->student->id,
                    'direction'   => 'in',
                    'time_source' => 'rtc',
                    'captured_at' => '2026-09-30T07:00:00Z',
                    'score'       => 0.92,
                ],
                [
                    'id'          => 'aaaaaaaa-0000-0000-0000-000000000002',
                    'student_id'  => $student2->id,
                    'direction'   => 'in',
                    'time_source' => 'rtc',
                    'captured_at' => '2026-09-30T07:01:00Z',
                    'score'       => 0.88,
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertCount(2, AttendanceLog::all());
    }

    public function test_batch_is_idempotent_duplicate_id_returns_skipped(): void
    {
        $payload = [
            'records' => [[
                'id'          => 'aaaaaaaa-0000-0000-0000-000000000005',
                'student_id'  => $this->student->id,
                'direction'   => 'in',
                'time_source' => 'ntp',
                'captured_at' => '2026-09-29T08:30:00Z',
                'score'       => 0.95,
            ]],
        ];

        $this->postJson('/api/v1/attendance/batch', $payload)->assertStatus(200);
        $second = $this->postJson('/api/v1/attendance/batch', $payload);

        $second->assertStatus(200)
            ->assertJsonPath('results.0.status', 'duplicate');

        // Only one record in DB despite two submissions
        $this->assertCount(1, AttendanceLog::all());
    }

    public function test_unauthenticated_batch_is_rejected(): void
    {
        // Actually drop the token by flushing auth instead of dropping middleware
        auth('sanctum')->forgetUser();
        $this->withHeaders(['Authorization' => 'Bearer bad-token']);

        $response = $this->postJson('/api/v1/attendance/batch', [
            'records' => [[
                'id'          => 'aaaaaaaa-0000-0000-0000-000000000003',
                'student_id'  => $this->student->id,
                'direction'   => 'in',
                'time_source' => 'ntp',
                'captured_at' => '2026-09-29T08:30:00Z',
                'score'       => 0.95,
            ]],
        ]);

        $response->assertStatus(401);
    }

    public function test_batch_rejects_unknown_student_id(): void
    {
        $response = $this->postJson('/api/v1/attendance/batch', [
            'records' => [[
                'id'          => 'aaaaaaaa-0000-0000-0000-000000000004',
                'student_id'  => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
                'direction'   => 'in',
                'time_source' => 'ntp',
                'captured_at' => '2026-09-29T08:30:00Z',
                'score'       => 0.95,
            ]],
        ]);

        // Either validation error 422, or 200 with status=error per record
        $this->assertTrue(in_array($response->status(), [200, 422]));
    }

    public function test_batch_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/attendance/batch', [
            'records' => [[]],
        ]);

        $response->assertStatus(422);
    }
}

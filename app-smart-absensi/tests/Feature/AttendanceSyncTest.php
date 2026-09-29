<?php

namespace Tests\Feature;

use App\Domain\Member\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_sync_attendance_records_from_edge_node(): void
    {
        $member = Member::create([
            'employee_number' => 'EMP-101',
            'name'            => 'Budi Santoso',
            'department'      => 'IT',
            'branch'          => 'Pusat',
            'is_active'       => true,
        ]);

        $payload = [
            'device_id' => 'ORANGEPI-001',
            'records'   => [
                [
                    'name'       => 'Budi Santoso',
                    'department' => 'IT',
                    'timestamp'  => '2026-09-29 08:30:00',
                    'confidence' => 0.95,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/sync/attendance', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status'       => 'success',
                'synced_count' => 1,
            ]);

        $this->assertDatabaseHas('attendances', [
            'name'       => 'Budi Santoso',
            'device_id'  => 'ORANGEPI-001',
            'member_id'  => $member->id,
            'confidence' => 0.95,
        ]);
    }

    public function test_can_get_member_templates_for_edge_node(): void
    {
        Member::create([
            'employee_number' => 'EMP-102',
            'name'            => 'Sari Dewi',
            'department'      => 'HR',
            'is_active'       => true,
        ]);

        $response = $this->getJson('/api/v1/sync/templates');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'count'  => 1,
            ]);
    }

    public function test_can_list_members_via_api(): void
    {
        Member::create([
            'employee_number' => 'EMP-103',
            'name'            => 'Ahmad Fauzi',
            'department'      => 'Finance',
            'is_active'       => true,
        ]);

        $response = $this->getJson('/api/v1/members');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Ahmad Fauzi');
    }
}

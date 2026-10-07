<?php

namespace Tests\Feature;

use App\Domain\Device\Models\Device;
use App\Domain\School\Models\Classroom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_config_returns_group_timing()
    {
        $device = Device::factory()->create();
        $school = \App\Domain\School\Models\School::factory()->create();
        
        $classroom1 = Classroom::create([
            'school_id' => $school->id,
            'name' => '10A',
            'type' => 'class',
            'start_time' => '07:00:00',
            'late_tolerance_minutes' => 15,
        ]);
        
        $classroom2 = Classroom::create([
            'school_id' => $school->id,
            'name' => 'Guru',
            'type' => 'staff',
            'start_time' => '06:30:00',
            'late_tolerance_minutes' => 30,
        ]);
        
        $device->groups()->attach([$classroom1->id, $classroom2->id]);

        \Laravel\Sanctum\Sanctum::actingAs($device, ['device']);
        $response = $this->getJson('/api/v1/config');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'groups' => [
                '*' => [
                    'id', 'name', 'type', 'start_time', 'late_tolerance_minutes', 'track_checkout'
                ]
            ],
            'mode',
            'confidence_threshold'
        ]);

        $data = $response->json();
        
        $this->assertCount(2, $data['groups']);
        $this->assertEquals('class', $data['groups'][0]['type']);
        $this->assertEquals('07:00:00', $data['groups'][0]['start_time']);
        $this->assertEquals(15, $data['groups'][0]['late_tolerance_minutes']);
        
        $this->assertEquals('staff', $data['groups'][1]['type']);
        $this->assertEquals('06:30:00', $data['groups'][1]['start_time']);
        $this->assertEquals(30, $data['groups'][1]['late_tolerance_minutes']);
    }

    public function test_device_config_without_groups()
    {
        $device = Device::factory()->create();

        \Laravel\Sanctum\Sanctum::actingAs($device, ['device']);
        $response = $this->getJson('/api/v1/config');

        $response->assertStatus(200);
        $data = $response->json();
        
        $this->assertEmpty($data['groups']);
    }
}

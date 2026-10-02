<?php

namespace Tests\Feature;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\PairingCode;
use App\Domain\School\Models\Building;
use App\Domain\School\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: Device Pairing & Heartbeat API
 * Covers: POST /api/v1/devices/pair · POST /api/v1/devices/heartbeat
 * Ref: PRD §4.2 Device Provisioning, AGENTS.md §Security (Rule 31)
 */
class DevicePairingTest extends TestCase
{
    use RefreshDatabase;

    // ─── POST /api/v1/devices/pair ───────────────────────────────────────

    public function test_device_can_pair_with_valid_code(): void
    {
        $building = Building::factory()->create();
        $user = \App\Domain\User\Models\User::factory()->create();

        $plain = 'ABCD-1234';
        PairingCode::create([
            'building_id' => $building->id,
            'code_hash'   => hash('sha256', $plain),
            'expires_at'  => now()->addMinutes(15),
            'created_by'  => $user->id,
        ]);

        $response = $this->postJson('/api/v1/devices/pair', [
            'code'          => $plain,
            'device_name'   => 'Kamera Gerbang Depan',
            'fw_version'    => '1.0.0',
            'model_version' => 'yunet-2303',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['device_id', 'token', 'building_id']);

        $this->assertDatabaseHas('devices', [
            'name'        => 'Kamera Gerbang Depan',
            'building_id' => $building->id,
        ]);
    }

    public function test_pairing_rejects_invalid_code(): void
    {
        $response = $this->postJson('/api/v1/devices/pair', [
            'code'          => 'XXXX-9999',
            'device_name'   => 'Fake Device',
            'fw_version'    => '1.0.0',
            'model_version' => 'yunet-2303',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_pairing_rejects_expired_code(): void
    {
        $building = Building::factory()->create();
        $user = \App\Domain\User\Models\User::factory()->create();
        $plain    = 'EXPR-0000';

        PairingCode::create([
            'building_id' => $building->id,
            'code_hash'   => hash('sha256', $plain),
            'expires_at'  => now()->subMinute(),   // already expired
            'created_by'  => $user->id,
        ]);

        $response = $this->postJson('/api/v1/devices/pair', [
            'code'          => $plain,
            'device_name'   => 'Late Device',
            'fw_version'    => '1.0.0',
            'model_version' => 'yunet-2303',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_pairing_code_is_one_time_use(): void
    {
        $building = Building::factory()->create();
        $user = \App\Domain\User\Models\User::factory()->create();
        $plain    = 'ONCE-0001';

        PairingCode::create([
            'building_id' => $building->id,
            'code_hash'   => hash('sha256', $plain),
            'expires_at'  => now()->addMinutes(15),
            'used_at'     => now(),                // already used
            'created_by'  => $user->id,
        ]);

        $response = $this->postJson('/api/v1/devices/pair', [
            'code'          => $plain,
            'device_name'   => 'Replay Device',
            'fw_version'    => '1.0.0',
            'model_version' => 'yunet-2303',
        ]);

        $response->assertStatus(422);
    }

    public function test_pairing_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/devices/pair', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'device_name', 'fw_version', 'model_version']);
    }

    // ─── POST /api/v1/devices/heartbeat ─────────────────────────────────

    public function test_authenticated_device_can_send_heartbeat(): void
    {
        $device = Device::factory()->create(['status' => 'online']);
        Sanctum::actingAs($device, ['device']);

        $response = $this->postJson('/api/v1/devices/heartbeat', [
            'fw_version'    => '1.0.0',
            'model_version' => 'yunet-2303',
            'cpu_temp'      => 52.4,
            'ram_free_mb'   => 400,
            'disk_free_mb'  => 5000,
            'fps'           => 30.5,
            'outbox_len'    => 3,
            'serial_ok'     => true,
            'ip_address'    => '192.168.10.50',
        ]);

        $response->assertStatus(200)->assertJson(['ok' => true]);

        $this->assertDatabaseHas('devices', [
            'id'         => $device->id,
            'ip_address' => '192.168.10.50',
        ]);
    }

    public function test_unauthenticated_heartbeat_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/devices/heartbeat', [
            'fw_version'    => '1.0.0',
            'model_version' => 'yunet-2303',
            'cpu_temp'      => 52.4,
            'ram_free_mb'   => 400,
            'disk_free_mb'  => 5000,
            'fps'           => 30.5,
            'outbox_len'    => 3,
            'serial_ok'     => true,
            'ip_address'    => '192.168.10.50',
        ]);

        $response->assertStatus(401);
    }
}


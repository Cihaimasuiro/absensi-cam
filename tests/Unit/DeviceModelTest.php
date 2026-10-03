<?php

namespace Tests\Unit;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\PairingCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit: Device Model & PairingCode Model
 * Covers: isOnline(), isExpired(), isUsed()
 * Ref: AGENTS.md §Model Rules (Rule 10)
 */
class DeviceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_is_online_when_heartbeat_within_3_minutes(): void
    {
        $device = Device::factory()->create([
            'last_heartbeat_at' => now()->subMinutes(2),
            'status'            => 'online',
        ]);

        $this->assertTrue($device->isOnline());
    }

    public function test_device_is_offline_when_heartbeat_older_than_3_minutes(): void
    {
        $device = Device::factory()->create([
            'last_heartbeat_at' => now()->subMinutes(4),
            'status'            => 'online',
        ]);

        $this->assertFalse($device->isOnline());
    }

    public function test_device_is_offline_when_never_seen(): void
    {
        $device = Device::factory()->create([
            'last_heartbeat_at' => null,
        ]);

        $this->assertFalse($device->isOnline());
    }

    public function test_pairing_code_is_expired_when_past(): void
    {
        $code = PairingCode::factory()->create([
            'expires_at' => now()->subSecond(),
        ]);

        $this->assertTrue($code->isExpired());
    }

    public function test_pairing_code_is_not_expired_when_future(): void
    {
        $code = PairingCode::factory()->create([
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->assertFalse($code->isExpired());
    }

    public function test_pairing_code_is_used_when_used_at_set(): void
    {
        $code = PairingCode::factory()->create([
            'used_at' => now(),
        ]);

        $this->assertTrue($code->isUsed());
    }

    public function test_pairing_code_is_not_used_when_null(): void
    {
        $code = PairingCode::factory()->create([
            'used_at' => null,
        ]);

        $this->assertFalse($code->isUsed());
    }
}

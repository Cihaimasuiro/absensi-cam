<?php

namespace Tests\Feature;

use App\Domain\Device\Models\Device;
use App\Domain\School\Models\Building;
use App\Domain\School\Models\School;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeviceWebCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Building $building;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $school = School::factory()->create();
        $this->building = Building::create([
            'school_id' => $school->id,
            'name' => 'Gedung A',
            'code' => 'BLD-A',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
    }

    public function test_device_index_page_loads_for_admin(): void
    {
        Device::create([
            'name' => 'Terminal 1',
            'device_code' => 'dev-0001',
            'building_id' => $this->building->id,
            'status' => 'offline',
        ]);

        $response = $this->actingAs($this->admin)->get(route('devices.index'));

        $response->assertStatus(200);
        $response->assertSee('Terminal 1');
        $response->assertSee('dev-0001');
        $response->assertSee('data-device=', false);
        $response->assertSee('x-ref="addModal"', false);
        $response->assertSee('x-ref="editModal"', false);
        $response->assertSee('x-ref="configModal"', false);
    }

    public function test_device_can_be_created_via_store(): void
    {
        $response = $this->actingAs($this->admin)->post(route('devices.store'), [
            'name' => 'Kamera Pintu Gerbang',
            'building_id' => $this->building->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionHas('new_device_env');
        $response->assertSessionHas('show_config_device');

        $this->assertDatabaseHas('devices', [
            'name' => 'Kamera Pintu Gerbang',
            'device_code' => 'dev-0001',
            'building_id' => $this->building->id,
            'status' => 'offline',
        ]);

        $device = Device::where('name', 'Kamera Pintu Gerbang')->first();
        $this->assertNotNull($device);
        $this->assertCount(1, $device->tokens);
    }

    public function test_device_can_be_updated(): void
    {
        $device = Device::create([
            'name' => 'Old Name',
            'device_code' => 'dev-0001',
            'building_id' => $this->building->id,
            'ip_address' => '192.168.1.10',
            'status' => 'offline',
            'liveness_enabled' => false,
        ]);

        $response = $this->actingAs($this->admin)->put(route('devices.update', $device), [
            'name' => 'New Name',
            'building_id' => $this->building->id,
            'ip_address' => '192.168.1.50',
            'liveness_enabled' => '1',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $device->refresh();
        $this->assertEquals('New Name', $device->name);
        $this->assertEquals('192.168.1.50', $device->ip_address);
        $this->assertTrue($device->liveness_enabled);
    }

    public function test_device_token_can_be_reset(): void
    {
        $device = Device::create([
            'name' => 'Kamera Lab',
            'device_code' => 'dev-0001',
            'building_id' => $this->building->id,
            'status' => 'online',
        ]);
        $device->createToken('old-token', ['device']);

        $response = $this->actingAs($this->admin)->post(route('devices.reset-token', $device));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionHas('new_device_env');
        $response->assertSessionHas('show_config_device');

        $device->refresh();
        $this->assertEquals('offline', $device->status);
        $this->assertCount(1, $device->tokens);
    }

    public function test_device_can_be_deleted(): void
    {
        $device = Device::create([
            'name' => 'To Delete',
            'device_code' => 'dev-0001',
            'building_id' => $this->building->id,
            'status' => 'offline',
        ]);
        $device->createToken('edge-token', ['device']);

        $response = $this->actingAs($this->admin)->delete(route('devices.destroy', $device));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\DevicePairingRequest;
use App\Domain\School\Models\Building;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeviceInitiatedPairingTest extends TestCase
{
    use RefreshDatabase;

    private Building $building;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'super_admin']);

        $this->building = Building::create([
            'name'      => 'Gedung A',
            'code'      => 'BLD-A',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create();
    }

    public function test_device_can_request_pairing_and_poll_status_pending(): void
    {
        $response = $this->postJson('/api/v1/devices/pairing/request', [
            'hostname'       => 'orangepilite2',
            'mac_address'    => '02:42:ac:11:00:02',
            'engine_version' => 'v0.2.0',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'pairing_code',
                'poll_token',
                'expires_at',
                'poll_interval_sec',
                'expires_in',
                'interval',
            ]);

        $pairingCode = $response->json('pairing_code');
        $pollToken = $response->json('poll_token');

        $this->assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{6}$/', $pairingCode);

        // Verify poll token is stored as SHA-256 hash in DB, NOT plain text
        $this->assertDatabaseHas('device_pairing_requests', [
            'pairing_code'    => $pairingCode,
            'poll_token_hash' => hash('sha256', $pollToken),
            'hostname'        => 'orangepilite2',
            'mac_address'     => '02:42:ac:11:00:02',
            'status'          => 'pending',
        ]);

        // Device polls while pending
        $pollResponse = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);

        $pollResponse->assertStatus(200)
            ->assertJson([
                'status'       => 'pending',
                'pairing_code' => $pairingCode,
            ]);
    }

    public function test_approved_pairing_delivers_credentials_and_burns_payload_preventing_reuse(): void
    {
        // 1. Device requests pairing
        $reqResponse = $this->postJson('/api/v1/devices/pairing/request', [
            'hostname'    => 'orangepilite2',
            'mac_address' => '02:42:ac:11:00:02',
        ]);

        $pollToken = $reqResponse->json('poll_token');
        $pairingReq = DevicePairingRequest::where('poll_token_hash', hash('sha256', $pollToken))->firstOrFail();

        // 2. Admin approves device
        $device = Device::create([
            'name'        => 'Terminal Absensi 1',
            'device_code' => 'dev-0001',
            'building_id' => $this->building->id,
            'status'      => 'offline',
        ]);

        $pairingReq->update([
            'status'      => 'approved',
            'approved_at' => now(),
            'approved_by' => $this->admin->id,
            'device_id'   => $device->id,
        ]);

        // 3. Device polls and receives credentials
        $pollResponse = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);

        $pollResponse->assertStatus(200)
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('device_code', 'dev-0001');

        $this->assertNotEmpty($pollResponse->json('credentials.token'));

        // 4. Verify request status transitioned to consumed
        $refreshed = $pairingReq->fresh();
        $this->assertEquals('consumed', $refreshed->status);

        // 5. Replay attempt: polling again must be rejected with HTTP 410 (consumed)
        $replayResponse = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);

        $replayResponse->assertStatus(410)
            ->assertJson([
                'status'  => 'consumed',
                'message' => 'Credentials already retrieved',
            ]);
    }

    public function test_expired_pairing_request_returns_410(): void
    {
        $pollToken = bin2hex(random_bytes(32));

        DevicePairingRequest::create([
            'pairing_code'    => 'ABC234',
            'poll_token_hash' => hash('sha256', $pollToken),
            'hostname'        => 'orangepilite2',
            'status'          => 'pending',
            'expires_at'      => now()->subMinute(),
        ]);

        $response = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);

        $response->assertStatus(410)
            ->assertJson([
                'status' => 'expired',
            ]);
    }

    public function test_rejected_pairing_request_returns_200_rejected(): void
    {
        $pollToken = bin2hex(random_bytes(32));

        DevicePairingRequest::create([
            'pairing_code'    => 'XYZ789',
            'poll_token_hash' => hash('sha256', $pollToken),
            'hostname'        => 'orangepilite2',
            'status'          => 'rejected',
            'expires_at'      => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'rejected',
            ]);
    }

    public function test_unknown_poll_token_returns_404(): void
    {
        $response = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => str_repeat('a', 64),
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'not_found',
            ]);
    }

    public function test_admin_can_approve_pending_pairing_request(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $this->admin->assignRole($role);

        // 1. Device requests pairing
        $reqResponse = $this->postJson('/api/v1/devices/pairing/request', [
            'hostname'    => 'orangepilite2',
            'mac_address' => '02:42:ac:11:00:55',
        ]);
        $pollToken = $reqResponse->json('poll_token');
        $pairingReq = DevicePairingRequest::where('poll_token_hash', hash('sha256', $pollToken))->firstOrFail();

        // 2. Admin views index page
        $indexResponse = $this->actingAs($this->admin)->get('/devices');
        $indexResponse->assertStatus(200)
            ->assertSee($pairingReq->pairing_code)
            ->assertSee('02:42:ac:11:00:55');

        // 3. Admin approves request via web route
        $approveResponse = $this->actingAs($this->admin)->post("/devices/pairing/{$pairingReq->id}/approve", [
            'building_id' => $this->building->id,
            'name'        => 'Kamera Gerbang 1',
        ]);
        $approveResponse->assertRedirect();
        $approveResponse->assertSessionHas('success');

        // 4. Verify request status is approved and device created
        $refreshed = $pairingReq->fresh();
        $this->assertEquals('approved', $refreshed->status);
        $this->assertNotNull($refreshed->device_id);

        $device = Device::find($refreshed->device_id);
        $this->assertEquals('Kamera Gerbang 1', $device->name);
        $this->assertEquals($this->building->id, $device->building_id);

        // 5. Device polls and gets credentials
        $pollResponse = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);
        $pollResponse->assertStatus(200)
            ->assertJson([
                'status'      => 'approved',
                'device_code' => $device->device_code,
            ]);
    }

    public function test_admin_can_reject_pending_pairing_request(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $this->admin->assignRole($role);

        $reqResponse = $this->postJson('/api/v1/devices/pairing/request', [
            'hostname'    => 'orangepilite2',
            'mac_address' => '02:42:ac:11:00:77',
        ]);
        $pollToken = $reqResponse->json('poll_token');
        $pairingReq = DevicePairingRequest::where('poll_token_hash', hash('sha256', $pollToken))->firstOrFail();

        $rejectResponse = $this->actingAs($this->admin)->post("/devices/pairing/{$pairingReq->id}/reject");
        $rejectResponse->assertRedirect();

        $refreshed = $pairingReq->fresh();
        $this->assertEquals('rejected', $refreshed->status);

        // Device poll returns 200 with status rejected
        $pollResponse = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);
        $pollResponse->assertStatus(200)
            ->assertJson([
                'status' => 'rejected',
            ]);
    }
}

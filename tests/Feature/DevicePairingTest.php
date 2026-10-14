<?php

namespace Tests\Feature;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\DevicePairingRequest;
use App\Domain\Device\Models\PairingCode;
use App\Domain\School\Models\Building;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature: Device Pairing & Heartbeat API
 * Covers:
 *   - Legacy Admin-Initiated: POST /api/v1/devices/pair
 *   - Device-Initiated Pairing: POST /api/v1/devices/pairing/request & POST /api/v1/devices/pairing/poll
 *   - Device Heartbeat: POST /api/v1/devices/heartbeat
 */
class DevicePairingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'super_admin']);
    }

    // ─── POST /api/v1/devices/pair (Legacy Admin Code) ───────────────────

    public function test_device_can_pair_with_valid_code(): void
    {
        $building = Building::factory()->create();
        $user = User::factory()->create();

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
            'model_version' => '3d9f1f77896fb3d1',
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
            'model_version' => '3d9f1f77896fb3d1',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_pairing_rejects_expired_code(): void
    {
        $building = Building::factory()->create();
        $user = User::factory()->create();
        $plain = 'EXPR-0000';

        PairingCode::create([
            'building_id' => $building->id,
            'code_hash'   => hash('sha256', $plain),
            'expires_at'  => now()->subMinute(),
            'created_by'  => $user->id,
        ]);

        $response = $this->postJson('/api/v1/devices/pair', [
            'code'          => $plain,
            'device_name'   => 'Late Device',
            'fw_version'    => '1.0.0',
            'model_version' => '3d9f1f77896fb3d1',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_pairing_code_is_one_time_use(): void
    {
        $building = Building::factory()->create();
        $user = User::factory()->create();
        $plain = 'ONCE-0001';

        PairingCode::create([
            'building_id' => $building->id,
            'code_hash'   => hash('sha256', $plain),
            'expires_at'  => now()->addMinutes(15),
            'used_at'     => now(),
            'created_by'  => $user->id,
        ]);

        $response = $this->postJson('/api/v1/devices/pair', [
            'code'          => $plain,
            'device_name'   => 'Replay Device',
            'fw_version'    => '1.0.0',
            'model_version' => '3d9f1f77896fb3d1',
        ]);

        $response->assertStatus(422);
    }

    public function test_pairing_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/devices/pair', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'device_name', 'fw_version', 'model_version']);
    }

    public function test_pairing_rejects_http_when_flag_false(): void
    {
        config(['app.pairing_allow_http' => false]);

        $response = $this->postJson('/api/v1/devices/pair', [
            'code'          => 'ABCD-1234',
            'device_name'   => 'Test HTTP Device',
            'fw_version'    => '1.0.0',
            'model_version' => '3d9f1f77896fb3d1',
        ]);

        $response->assertStatus(403);
    }

    public function test_pairing_accepts_https_when_flag_false(): void
    {
        config(['app.pairing_allow_http' => false]);

        $building = Building::factory()->create();
        $user = User::factory()->create();
        $plain = 'ABCD-1234';
        PairingCode::create([
            'building_id' => $building->id,
            'code_hash'   => hash('sha256', $plain),
            'expires_at'  => now()->addMinutes(15),
            'created_by'  => $user->id,
        ]);

        $response = $this->postJson('https://localhost/api/v1/devices/pair', [
            'code'          => $plain,
            'device_name'   => 'Test HTTPS Device',
            'fw_version'    => '1.0.0',
            'model_version' => '3d9f1f77896fb3d1',
        ]);

        $response->assertStatus(201);
    }

    // ─── DEVICE-INITIATED PAIRING (TV / IoT Style) ──────────────────────

    public function test_device_initiated_pairing_request_creates_record_with_sha256_hashed_poll_token(): void
    {
        $response = $this->postJson('/api/v1/devices/pairing/request', [
            'hostname'       => 'orangepilite2-gate-in',
            'mac_address'    => '02:42:c0:a8:01:32',
            'engine_version' => '0.2.0',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'pairing_code',
                'poll_token',
                'expires_at',
                'poll_interval_sec',
            ]);

        $pairingCode = $response->json('pairing_code');
        $pollToken = $response->json('poll_token');

        // Check pairing_code format: 6 characters, uppercase + digits excluding 0, O, 1, I
        $this->assertEquals(6, strlen($pairingCode));
        $this->assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{6}$/', $pairingCode);
        $this->assertEquals(64, strlen($pollToken));

        // Verify poll_token is stored as SHA-256 hash, NEVER plaintext
        $this->assertDatabaseHas('device_pairing_requests', [
            'pairing_code'    => $pairingCode,
            'poll_token_hash' => hash('sha256', $pollToken),
            'hostname'        => 'orangepilite2-gate-in',
            'mac_address'     => '02:42:c0:a8:01:32',
            'engine_version'  => '0.2.0',
            'status'          => 'pending',
        ]);

        $this->assertDatabaseMissing('device_pairing_requests', [
            'poll_token_hash' => $pollToken,
        ]);
    }

    public function test_device_repeat_pairing_request_from_same_mac_cancels_previous_pending_request(): void
    {
        $mac = '02:42:c0:a8:01:55';

        // First pairing request
        $res1 = $this->postJson('/api/v1/devices/pairing/request', [
            'hostname'    => 'orangepi-term-1',
            'mac_address' => $mac,
        ]);
        $res1->assertStatus(201);
        $code1 = $res1->json('pairing_code');

        $this->assertDatabaseHas('device_pairing_requests', [
            'pairing_code' => $code1,
            'status'       => 'pending',
        ]);

        // Second request from same MAC (e.g. after reboot)
        $res2 = $this->postJson('/api/v1/devices/pairing/request', [
            'hostname'    => 'orangepi-term-1',
            'mac_address' => $mac,
        ]);
        $res2->assertStatus(201);
        $code2 = $res2->json('pairing_code');

        // First request is invalidated to expired
        $this->assertDatabaseHas('device_pairing_requests', [
            'pairing_code' => $code1,
            'status'       => 'expired',
        ]);

        // Second request is active pending
        $this->assertDatabaseHas('device_pairing_requests', [
            'pairing_code' => $code2,
            'status'       => 'pending',
        ]);

        $pendingCount = DevicePairingRequest::where('mac_address', $mac)
            ->where('status', 'pending')
            ->count();
        $this->assertEquals(1, $pendingCount);
    }

    public function test_poll_while_pending_returns_pending_status(): void
    {
        $initRes = $this->postJson('/api/v1/devices/pairing/request', [
            'hostname'    => 'orangepi-gate',
            'mac_address' => '02:42:c0:a8:01:77',
        ]);
        $initRes->assertStatus(201);
        $pollToken = $initRes->json('poll_token');

        // Poll with body JSON
        $pollRes = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);

        $pollRes->assertStatus(200)
            ->assertJson(['status' => 'pending']);

        // Poll with Bearer token header
        $pollBearerRes = $this->withToken($pollToken)->postJson('/api/v1/devices/pairing/poll');
        $pollBearerRes->assertStatus(200)
            ->assertJson(['status' => 'pending']);
    }

    public function test_full_pairing_flow_request_admin_approve_and_one_time_credential_retrieval(): void
    {
        // 1. Device initiates pairing request
        $initRes = $this->postJson('/api/v1/devices/pairing/request', [
            'hostname'       => 'orangepi-pos-1',
            'mac_address'    => '02:42:c0:a8:01:99',
            'engine_version' => '0.2.0',
        ]);
        $initRes->assertStatus(201);
        $pollToken = $initRes->json('poll_token');
        $pairingCode = $initRes->json('pairing_code');

        $pairingRecord = DevicePairingRequest::where('pairing_code', $pairingCode)->firstOrFail();
        $this->assertEquals('pending', $pairingRecord->status);
        $this->assertNull($pairingRecord->device_id);

        // 2. Admin approves pairing in dashboard
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $building = Building::factory()->create(['name' => 'Gedung Utama']);

        $approveRes = $this->actingAs($admin)->post(route('devices.pairing.approve', $pairingRecord->id), [
            'building_id' => $building->id,
            'name'        => 'Kamera Gerbang Masuk',
        ]);
        $approveRes->assertRedirect();

        $refreshed = $pairingRecord->fresh();
        $this->assertEquals('approved', $refreshed->status);
        $this->assertNotNull($refreshed->device_id);

        $device = Device::find($refreshed->device_id);
        $this->assertNotNull($device);
        $this->assertEquals('Kamera Gerbang Masuk', $device->name);
        $this->assertEquals($building->id, $device->building_id);
        // Note: token is NOT yet created prior to first poll
        $this->assertNull($device->token_hash);

        // 3. First poll by device retrieves credentials and issues token
        $pollRes1 = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);

        $pollRes1->assertStatus(200)
            ->assertJsonPath('status', 'approved')
            ->assertJsonStructure([
                'status',
                'credentials' => [
                    'url',
                    'token',
                    'device_id',
                    'device_code',
                    'embed_key',
                    'model_version',
                ],
            ]);

        $token = $pollRes1->json('credentials.token');
        $this->assertNotEmpty($token);

        // Device token_hash is now stored, and status transitioned to consumed
        $this->assertEquals('consumed', $pairingRecord->fresh()->status);
        $this->assertNotNull($device->fresh()->token_hash);
        $this->assertEquals(hash('sha256', $token), $device->fresh()->token_hash);

        // 4. Second poll with same poll_token is rejected with 410 consumed (one-time delivery)
        $pollRes2 = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);

        $pollRes2->assertStatus(410)
            ->assertJson([
                'status'  => 'consumed',
                'message' => 'Credentials already retrieved',
            ]);
    }

    public function test_pairing_reject_flow_returns_rejected_status(): void
    {
        $initRes = $this->postJson('/api/v1/devices/pairing/request', [
            'hostname'    => 'orangepi-unknown',
            'mac_address' => '02:42:c0:a8:01:88',
        ]);
        $initRes->assertStatus(201);
        $pollToken = $initRes->json('poll_token');
        $pairingCode = $initRes->json('pairing_code');

        $pairingRecord = DevicePairingRequest::where('pairing_code', $pairingCode)->firstOrFail();

        // Admin rejects pairing
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $rejectRes = $this->actingAs($admin)->post(route('devices.pairing.reject', $pairingRecord->id));
        $rejectRes->assertRedirect();

        $this->assertEquals('rejected', $pairingRecord->fresh()->status);

        // Poll returns 200 with status rejected
        $pollRes = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);

        $pollRes->assertStatus(200)
            ->assertJson(['status' => 'rejected']);
    }

    public function test_poll_on_expired_request_returns_410_expired(): void
    {
        $initRes = $this->postJson('/api/v1/devices/pairing/request', [
            'hostname'    => 'orangepi-timedout',
            'mac_address' => '02:42:c0:a8:01:66',
        ]);
        $initRes->assertStatus(201);
        $pollToken = $initRes->json('poll_token');
        $pairingCode = $initRes->json('pairing_code');

        $pairingRecord = DevicePairingRequest::where('pairing_code', $pairingCode)->firstOrFail();
        $pairingRecord->update(['expires_at' => now()->subMinute()]);

        $pollRes = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);

        $pollRes->assertStatus(410)
            ->assertJson(['status' => 'expired']);

        $this->assertEquals('expired', $pairingRecord->fresh()->status);
    }

    // ─── POST /api/v1/devices/heartbeat ─────────────────────────────────

    public function test_authenticated_device_can_send_heartbeat(): void
    {
        $device = Device::factory()->create(['status' => 'online']);
        Sanctum::actingAs($device, ['device']);

        $response = $this->postJson('/api/v1/devices/heartbeat', [
            'fw_version'    => '1.0.0',
            'model_version' => '3d9f1f77896fb3d1',
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
            'model_version' => '3d9f1f77896fb3d1',
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

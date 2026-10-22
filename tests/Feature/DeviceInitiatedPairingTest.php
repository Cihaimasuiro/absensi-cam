<?php

namespace Tests\Feature;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\DevicePairingRequest;
use App\Domain\School\Models\Building;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class DeviceInitiatedPairingTest extends TestCase
{
    use RefreshDatabase;

    private Building $building;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

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
            'hostname'      => 'orangepilite2',
            'mac_address'   => '02:42:ac:11:00:02',
            'model_version' => 'v0.2.0',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'pairing_code',
                'poll_token',
                'expires_in',
                'interval',
            ]);

        $pairingCode = $response->json('pairing_code');
        $pollToken = $response->json('poll_token');

        $this->assertMatchesRegularExpression('/^\d{3}-\d{3}$/', $pairingCode);

        // Pastikan poll token tersimpan sebagai hash SHA-256 di DB, BUKAN plain text
        $this->assertDatabaseHas('device_pairing_requests', [
            'pairing_code'    => $pairingCode,
            'poll_token_hash' => hash('sha256', $pollToken),
            'hostname'        => 'orangepilite2',
            'mac_address'     => '02:42:ac:11:00:02',
            'status'          => 'pending',
        ]);

        // Device melakukan poll saat status masih pending
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
        // 1. Device meminta pairing
        $reqResponse = $this->postJson('/api/v1/devices/pairing/request', [
            'hostname'    => 'orangepilite2',
            'mac_address' => '02:42:ac:11:00:02',
        ]);

        $pollToken = $reqResponse->json('poll_token');
        $pairingReq = DevicePairingRequest::where('poll_token_hash', hash('sha256', $pollToken))->firstOrFail();

        // 2. Admin menyetujui device
        $device = Device::create([
            'id'          => (string) \Illuminate\Support\Str::uuid(),
            'name'        => 'Terminal Absensi 1',
            'device_code' => 'dev-0001',
            'building_id' => $this->building->id,
            'status'      => 'offline',
        ]);
        $plainToken = $device->createToken('edge-engine', ['device'])->plainTextToken;
        $device->update(['token_hash' => hash('sha256', $plainToken)]);

        $credentials = [
            'url'           => 'http://192.168.10.66:8000',
            'token'         => $plainToken,
            'device_id'     => (string) $device->id,
            'device_code'   => $device->device_code,
            'embed_key'     => 'test-secret-key-32-bytes-length!',
            'model_version' => 'v0.2.0',
        ];

        $pairingReq->update([
            'status'            => 'approved',
            'approved_at'       => now(),
            'approved_by'       => $this->admin->id,
            'device_id'         => $device->id,
            'encrypted_payload' => Crypt::encryptString(json_encode($credentials)),
        ]);

        // 3. Device melakukan poll dan menerima credentials
        $pollResponse = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);

        $pollResponse->assertStatus(200)
            ->assertJson([
                'status'      => 'approved',
                'token'       => $plainToken,
                'device_code' => 'dev-0001',
            ]);

        // 4. Pastikan payload credentials sudah dihapus permanen dari DB (pengiriman sekali pakai)
        $refreshed = $pairingReq->fresh();
        $this->assertEquals('claimed', $refreshed->status);
        $this->assertNull($refreshed->encrypted_payload);
        $this->assertNotNull($refreshed->claimed_at);

        // 5. Percobaan replay: poll lagi harus ditolak dengan HTTP 410 (claimed)
        $replayResponse = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);

        $replayResponse->assertStatus(410)
            ->assertJson([
                'status' => 'claimed',
            ]);
    }

    public function test_expired_pairing_request_returns_410(): void
    {
        $pollToken = bin2hex(random_bytes(32));

        DevicePairingRequest::create([
            'pairing_code'    => '123-456',
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

    public function test_rejected_pairing_request_returns_403(): void
    {
        $pollToken = bin2hex(random_bytes(32));

        DevicePairingRequest::create([
            'pairing_code'    => '123-456',
            'poll_token_hash' => hash('sha256', $pollToken),
            'hostname'        => 'orangepilite2',
            'status'          => 'rejected',
            'expires_at'      => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'rejected',
            ]);
    }

    public function test_flooding_pending_requests_is_rejected_after_limit(): void
    {
        // Buat 3 request pending aktif dari MAC yang sama
        for ($i = 0; $i < 3; $i++) {
            $resp = $this->postJson('/api/v1/devices/pairing/request', [
                'hostname'    => 'orangepilite2',
                'mac_address' => '02:42:ac:11:00:99',
            ]);
            $resp->assertStatus(201);
        }

        // Request ke-4 harus ditolak dengan 422
        $resp4 = $this->postJson('/api/v1/devices/pairing/request', [
            'hostname'    => 'orangepilite2',
            'mac_address' => '02:42:ac:11:00:99',
        ]);

        $resp4->assertStatus(422)
            ->assertJsonValidationErrors(['pairing']);
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
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        $this->admin->assignRole($role);

        // 1. Device meminta pairing
        $reqResponse = $this->postJson('/api/v1/devices/pairing/request', [
            'hostname'    => 'orangepilite2',
            'mac_address' => '02:42:ac:11:00:55',
        ]);
        $pollToken = $reqResponse->json('poll_token');
        $pairingReq = DevicePairingRequest::where('poll_token_hash', hash('sha256', $pollToken))->firstOrFail();

        // 2. Admin membuka halaman index
        $indexResponse = $this->actingAs($this->admin)->get('/devices');
        $indexResponse->assertStatus(200)
            ->assertSee($pairingReq->pairing_code)
            ->assertSee('02:42:ac:11:00:55');

        // 3. Admin menyetujui request lewat web route
        $approveResponse = $this->actingAs($this->admin)->post("/devices/pairing/{$pairingReq->id}/approve", [
            'building_id' => $this->building->id,
            'name'        => 'Kamera Gerbang 1',
        ]);
        $approveResponse->assertRedirect();
        $approveResponse->assertSessionHas('success');

        // 4. Pastikan status request approved dan device sudah dibuat
        $refreshed = $pairingReq->fresh();
        $this->assertEquals('approved', $refreshed->status);
        $this->assertNotNull($refreshed->device_id);
        $this->assertNotNull($refreshed->encrypted_payload);

        $device = Device::find($refreshed->device_id);
        $this->assertEquals('Kamera Gerbang 1', $device->name);
        $this->assertEquals($this->building->id, $device->building_id);

        // 5. Device melakukan poll dan mendapatkan credentials
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
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
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

        // Poll dari device mengembalikan 403
        $pollResponse = $this->postJson('/api/v1/devices/pairing/poll', [
            'poll_token' => $pollToken,
        ]);
        $pollResponse->assertStatus(403)
            ->assertJson([
                'status' => 'rejected',
            ]);
    }
}

<?php

namespace App\Http\Controllers\Web;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\PairingCode;
use App\Domain\School\Models\Building;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeviceDiscoveryController extends Controller
{
    /**
     * UDP-scan the local network for Edge Engines.
     */
    public function scan()
    {
        // ponytail: skip NetworkScannerService injection — direct UDP broadcast here is simplest
        $devices = $this->udpScan();
        return response()->json(['devices' => $devices]);
    }

    /**
     * Auto-pair with a discovered device:
     * 1. Generate pairing code for the building the admin selects.
     * 2. POST credentials to Edge Engine's /auto-pair HTTP endpoint.
     * 3. Edge Engine calls back /api/v1/devices/pair to exchange the code for a Sanctum token.
     */
    public function autoPair(Request $request)
    {
        $request->validate([
            'ip_address'  => ['required', 'ip'],
            'port'        => ['required', 'integer', 'min:1', 'max:65535'],
            'building_id' => ['required', 'exists:buildings,id'],
        ]);

        $ip         = $request->ip_address;
        $port       = (int) $request->port;
        $buildingId = $request->building_id;

        // Generate one-time pairing code (same logic as DeviceController@generatePairingCode)
        $plain = strtoupper(Str::random(4) . '-' . Str::random(4));
        $hash  = hash('sha256', $plain);

        $pairingCode = PairingCode::create([
            'building_id' => $buildingId,
            'code_hash'   => $hash,
            'expires_at'  => now()->addMinutes(5), // shorter TTL for auto-pair
            'created_by'  => auth()->id() ?? 1,
        ]);

        $backendUrl = config('app.url');
        $edgeUrl    = "http://{$ip}:{$port}/auto-pair";

        try {
            $response = Http::timeout(5)->post($edgeUrl, [
                'backend_url' => $backendUrl,
                'pairing_code' => $plain,        // Edge Engine will POST this back to /api/v1/devices/pair
            ]);

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Perangkat berhasil dipairing dan sedang restart.',
                ]);
            }

            $pairingCode->delete();

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim konfigurasi ke perangkat: ' . $response->body(),
            ], 400);

        } catch (\Exception $e) {
            $pairingCode->delete();
            Log::error('Auto-pairing failed', ['ip' => $ip, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat terhubung ke perangkat. Pastikan IP valid dan Engine sedang berjalan.',
            ], 500);
        }
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function udpScan(): array
    {
        $devices = [];
        $socket  = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if (! $socket) {
            return [];
        }

        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
        @socket_sendto($socket, 'ABSENSI_DISCOVER', 16, 0, '255.255.255.255', 55555);

        $start = time();
        while ((time() - $start) < 2) {
            $buffer = '';
            $ip     = '';
            $port   = 0;
            $bytes  = @socket_recvfrom($socket, $buffer, 1024, 0, $ip, $port);

            if ($bytes !== false && $buffer) {
                $data = json_decode($buffer, true);
                if ($data && isset($data['device_id'])) {
                    $devices[$data['device_id']] = [
                        'device_id'  => $data['device_id'],
                        'ip_address' => $ip,
                        'status'     => $data['status'] ?? 'unknown',
                        'port'       => $data['port'] ?? 5000,
                    ];
                }
            }
        }

        socket_close($socket);

        return array_values($devices);
    }
}

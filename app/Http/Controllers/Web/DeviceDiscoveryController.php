<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Domain\Device\Services\NetworkScannerService;
use App\Domain\Device\Models\PairingCode;
use App\Domain\Device\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeviceDiscoveryController extends Controller
{
    private NetworkScannerService $scanner;

    public function __construct(NetworkScannerService $scanner)
    {
        $this->scanner = $scanner;
    }

    /**
     * Scan the local network for Edge Engines.
     */
    public function scan()
    {
        $devices = $this->scanner->scan();
        return response()->json(['devices' => $devices]);
    }

    /**
     * Automatically pair with a discovered device on the network.
     */
    public function autoPair(Request $request)
    {
        $request->validate([
            'ip_address' => 'required|ip',
            'port' => 'required|integer',
            'name' => 'required|string|max:255',
        ]);

        $ip = $request->ip_address;
        $port = $request->port;

        // 1. Create Device record
        $device = Device::create([
            'name' => $request->name,
            'status' => 'offline',
            'last_heartbeat_at' => null,
            'settings' => [],
        ]);

        // 2. Generate Pairing Code
        $pairingCode = PairingCode::generateForDevice($device);
        
        // 3. Send payload to Edge Engine
        $edgeUrl = "http://{$ip}:{$port}/auto-pair";
        $backendUrl = config('app.url');

        try {
            $response = Http::timeout(5)->post($edgeUrl, [
                'backend_url' => $backendUrl,
                'token' => $pairingCode->code,
            ]);

            if ($response->successful()) {
                return response()->json([
                    'success' => true, 
                    'message' => 'Perangkat berhasil dipairing dan sedang restart.'
                ]);
            }

            // Cleanup if failed
            $pairingCode->delete();
            $device->delete();

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim konfigurasi ke perangkat: ' . $response->body()
            ], 400);

        } catch (\Exception $e) {
            // Cleanup if failed
            $pairingCode->delete();
            $device->delete();

            Log::error('Auto-pairing failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat terhubung ke perangkat. Pastikan IP valid dan perangkat menyala.'
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers\Web;

use App\Domain\Device\Actions\ApproveDevicePairingRequest;
use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\DevicePairingRequest;
use App\Domain\School\Models\Building;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        $devices = Device::with('building:id,name')
            ->select(['id', 'name', 'device_code', 'building_id', 'fw_version', 'model_version',
                      'last_heartbeat_at', 'last_cpu_temp', 'last_outbox_len', 'status', 'ip_address', 'liveness_enabled'])
            ->orderBy('name')
            ->get();

        $buildings = Building::active()->select(['id', 'name'])->orderBy('name')->get();

        $clientIp = $request->ip();
        $pendingPairings = DevicePairingRequest::pending()
            ->orderByDesc('created_at')
            ->get()
            ->sortByDesc(fn($p) => ($p->ip_address === $clientIp ? 1 : 0));

        return view('devices.index', compact('devices', 'buildings', 'pendingPairings', 'clientIp'));
    }

    /** Setujui permintaan pairing device-initiated */
    public function approvePairing(
        Request $request,
        DevicePairingRequest $pairingRequest,
        ApproveDevicePairingRequest $action
    ) {
        $validated = $request->validate([
            'building_id' => ['required', 'exists:buildings,id'],
            'name'        => ['nullable', 'string', 'max:100'],
        ]);

        $device = $action->execute($pairingRequest, $validated, auth()->user());

        return back()->with('success', "Perangkat {$device->name} ({$device->device_code}) berhasil disetujui. Mesin akan segera aktif otomatis.");
    }

    /** Tolak permintaan pairing device-initiated */
    public function rejectPairing(DevicePairingRequest $pairingRequest)
    {
        $pairingRequest->update(['status' => 'rejected']);

        return back()->with('success', "Permintaan pairing ({$pairingRequest->pairing_code}) telah ditolak.");
    }

    /** Create a device and generate an API token instantly */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'building_id' => ['required', 'exists:buildings,id'],
        ]);

        $lastCode = \Illuminate\Support\Facades\DB::table('devices')
            ->select('device_code')
            ->get()
            ->map(function ($row) {
                return preg_match('/dev-(\d+)/', $row->device_code ?? '', $m) ? (int) $m[1] : 0;
            })
            ->max();

        $deviceCode = 'dev-' . str_pad(($lastCode ?? 0) + 1, 4, '0', STR_PAD_LEFT);

        $device = Device::create([
            'name' => $validated['name'],
            'device_code' => $deviceCode,
            'building_id' => $validated['building_id'],
            'status' => 'offline',
        ]);

        // Generate Sanctum token
        $token = $device->createToken('edge-token', ['device'])->plainTextToken;

        activity('device')
            ->performedOn($device)
            ->causedBy(auth()->user())
            ->log('Device created and token generated');

        $serverUrl = $request->getSchemeAndHttpHost();
        $embedKey = config('app.enrollment_embed_key', '');
        $envContent = <<<ENV
SMART_ABSENSI_URL={$serverUrl}
SMART_ABSENSI_TOKEN={$token}
SMART_ABSENSI_DEVICE_ID={$deviceCode}
ENROLLMENT_EMBED_KEY={$embedKey}
ENV;

        return back()
            ->with('new_device_env', $envContent)
            ->with('show_config_device', $device->toJson())
            ->with('success', 'Perangkat berhasil ditambahkan.');
    }

    /** Mark device offline / revoke Sanctum token */
    public function revoke(Device $device)
    {
        $device->tokens()->delete();
        $device->update(['status' => 'offline', 'token_hash' => null]);

        activity('device')
            ->performedOn($device)
            ->causedBy(auth()->user())
            ->log('Device revoked');

        return back()->with('success', "Token perangkat {$device->name} dicabut.");
    }

    /** Reset token and show configuration again */
    public function resetToken(Request $request, Device $device)
    {
        $device->tokens()->delete();
        $token = $device->createToken('edge-token', ['device'])->plainTextToken;
        
        $device->update(['status' => 'offline']);

        activity('device')
            ->performedOn($device)
            ->causedBy(auth()->user())
            ->log('Device token reset');

        $serverUrl = $request->getSchemeAndHttpHost();
        $embedKey = config('app.enrollment_embed_key', '');
        $envContent = <<<ENV
SMART_ABSENSI_URL={$serverUrl}
SMART_ABSENSI_TOKEN={$token}
SMART_ABSENSI_DEVICE_ID={$device->device_code}
ENROLLMENT_EMBED_KEY={$embedKey}
ENV;

        return back()
            ->with('new_device_env', $envContent)
            ->with('show_config_device', $device->toJson())
            ->with('success', "Token untuk perangkat {$device->name} berhasil di-reset.");
    }

    /** Permanently delete the device */
    public function destroy(Device $device)
    {
        $name = $device->name;
        $device->tokens()->delete();
        $device->delete();

        activity('device')
            ->causedBy(auth()->user())
            ->log("Device {$name} deleted");

        return back()->with('success', "Perangkat {$name} berhasil dihapus.");
    }

    /** Update device details */
    public function update(Request $request, Device $device)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'building_id' => ['required', 'exists:buildings,id'],
            'ip_address' => ['nullable', 'string', 'max:45'],
        ]);

        $validated['liveness_enabled'] = $request->boolean('liveness_enabled');

        $device->update($validated);

        activity('device')
            ->performedOn($device)
            ->causedBy(auth()->user())
            ->log('Device updated');

        return back()->with('success', "Perangkat {$device->name} berhasil diperbarui.");
    }
}

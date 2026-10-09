<?php

namespace App\Http\Controllers\Web;

use App\Domain\Device\Models\Device;
use App\Domain\School\Models\Building;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function index()
    {
        $devices = Device::with('building:id,name')
            ->select(['id', 'name', 'device_code', 'building_id', 'fw_version', 'model_version',
                      'last_heartbeat_at', 'last_cpu_temp', 'last_outbox_len', 'status', 'ip_address', 'liveness_enabled'])
            ->orderBy('name')
            ->get();

        $buildings = Building::active()->select(['id', 'name'])->orderBy('name')->get();

        return view('devices.index', compact('devices', 'buildings'));
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
            ->map(fn($row) => (int) str_replace('dev-', '', $row->device_code))
            ->max();

        $deviceCode = 'dev-' . str_pad(($lastCode ?? 0) + 1, 4, '0', STR_PAD_LEFT);

        $device = Device::create([
            'id' => \Illuminate\Support\Str::uuid(),
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

        $envContent = <<<ENV
SMART_ABSENSI_URL=http://{$request->getHost()}:8000
SMART_ABSENSI_TOKEN={$token}
SMART_ABSENSI_DEVICE_ID={$deviceCode}
ENROLLMENT_EMBED_KEY=t7rXp59ZkE+L9Y6qP3R8FmNcK5WxYJ2HhDbjPvC4RnE=
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

        $envContent = <<<ENV
SMART_ABSENSI_URL={$request->getSchemeAndHttpHost()}
SMART_ABSENSI_TOKEN={$token}
SMART_ABSENSI_DEVICE_ID={$device->device_code}
ENROLLMENT_EMBED_KEY=t7rXp59ZkE+L9Y6qP3R8FmNcK5WxYJ2HhDbjPvC4RnE=
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

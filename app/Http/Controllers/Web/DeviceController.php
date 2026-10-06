<?php

namespace App\Http\Controllers\Web;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\PairingCode;
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
                      'last_heartbeat_at', 'last_cpu_temp', 'last_outbox_len', 'status', 'ip_address'])
            ->orderBy('name')
            ->get();

        $buildings = Building::active()->select(['id', 'name'])->orderBy('name')->get();

        return view('devices.index', compact('devices', 'buildings'));
    }

    /** Generate a one-time pairing code for a building */
    public function generatePairingCode(Request $request)
    {
        $validated = $request->validate([
            'building_id' => ['required', 'exists:buildings,id'],
        ]);

        // Plain 8-char alphanumeric code split K7M2-9QXA style
        $plain = strtoupper(Str::random(4).'-'.Str::random(4));
        $hash  = hash('sha256', $plain);

        $codeRecord = PairingCode::create([
            'building_id'  => $validated['building_id'],
            'code_hash'  => $hash,
            'expires_at' => now()->addMinutes(15),
            'created_by' => auth()->id(),
        ]);

        activity('device')
            ->performedOn($codeRecord)
            ->causedBy(auth()->user())
            ->log('Pairing code generated');

        return back()->with('pairing_code', $plain)->with('success', 'Kode pairing berhasil dibuat (berlaku 15 menit).');
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
}

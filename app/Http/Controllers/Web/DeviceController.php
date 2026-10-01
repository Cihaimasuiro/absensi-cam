<?php

namespace App\Http\Controllers\Web;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\PairingCode;
use App\Domain\Organization\Models\Branch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function index()
    {
        $devices = Device::with('branch:id,name')
            ->select(['id', 'name', 'device_code', 'branch_id', 'fw_version', 'model_version',
                      'last_heartbeat_at', 'last_cpu_temp', 'last_outbox_len', 'status', 'ip_address'])
            ->orderBy('name')
            ->get();

        $branches = Branch::active()->select(['id', 'name'])->orderBy('name')->get();

        return view('devices.index', compact('devices', 'branches'));
    }

    /** Generate a one-time pairing code for a branch */
    public function generatePairingCode(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
        ]);

        // Plain 8-char alphanumeric code split K7M2-9QXA style
        $plain = strtoupper(Str::random(4).'-'.Str::random(4));
        $hash  = hash('sha256', $plain);

        PairingCode::create([
            'branch_id'  => $validated['branch_id'],
            'code_hash'  => $hash,
            'expires_at' => now()->addMinutes(15),
            'created_by' => auth()->id() ?? 1, // fallback; real auth wired in Step 4
        ]);

        return back()->with('pairing_code', $plain)->with('success', 'Kode pairing berhasil dibuat (berlaku 15 menit).');
    }

    /** Mark device offline / revoke Sanctum token */
    public function revoke(Device $device)
    {
        $device->tokens()->delete();
        $device->update(['status' => 'offline', 'token_hash' => null]);

        return back()->with('success', "Token perangkat {$device->name} dicabut.");
    }
}

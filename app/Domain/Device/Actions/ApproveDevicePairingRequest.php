<?php

namespace App\Domain\Device\Actions;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\DevicePairingRequest;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApproveDevicePairingRequest
{
    public function execute(DevicePairingRequest $pairingRequest, array $data, User $admin): Device
    {
        if ($pairingRequest->status !== 'pending' || $pairingRequest->isExpired()) {
            throw ValidationException::withMessages([
                'pairing' => ['Permintaan pairing ini sudah tidak valid atau telah kedaluwarsa.'],
            ]);
        }

        return DB::transaction(function () use ($pairingRequest, $data, $admin) {
            // Determine next device_code (e.g. dev-0001)
            $lastCode = DB::table('devices')
                ->select('device_code')
                ->get()
                ->map(fn($row) => (int) str_replace('dev-', '', $row->device_code ?? '0'))
                ->max();

            $deviceCode = 'dev-' . str_pad(($lastCode ?? 0) + 1, 4, '0', STR_PAD_LEFT);
            $deviceName = $data['name'] ?? $pairingRequest->hostname ?? "Device-{$deviceCode}";

            $device = Device::create([
                'id'            => (string) Str::uuid(),
                'name'          => $deviceName,
                'device_code'   => $deviceCode,
                'building_id'   => $data['building_id'],
                'ip_address'    => $pairingRequest->ip_address,
                'model_version' => $pairingRequest->model_version ?? config('app.model_version'),
                'status'        => 'offline',
            ]);

            // Create Sanctum token
            $plainToken = $device->createToken('edge-engine', ['device'])->plainTextToken;
            $device->update(['token_hash' => hash('sha256', $plainToken)]);

            // Prepare credentials payload for polling edge client
            $embedKey = env('ENROLLMENT_EMBED_KEY', 't7rXp59ZkE+L9Y6qP3R8FmNcK5WxYJ2HhDbjPvC4RnE=');
            $serverUrl = config('app.url') ?: 'http://127.0.0.1:8000';

            $payload = [
                'url'           => $serverUrl,
                'token'         => $plainToken,
                'device_id'     => (string) $device->id,
                'device_code'   => $device->device_code,
                'embed_key'     => $embedKey,
                'model_version' => $device->model_version,
            ];

            $pairingRequest->update([
                'status'            => 'approved',
                'approved_at'       => now(),
                'approved_by'       => $admin->id,
                'device_id'         => $device->id,
                'encrypted_payload' => Crypt::encryptString(json_encode($payload)),
            ]);

            activity('device')
                ->performedOn($device)
                ->causedBy($admin)
                ->log("Perangkat {$device->name} disetujui via device-initiated pairing ({$pairingRequest->pairing_code})");

            return $device;
        });
    }
}

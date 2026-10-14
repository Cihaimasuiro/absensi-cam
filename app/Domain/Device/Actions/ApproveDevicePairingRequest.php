<?php

namespace App\Domain\Device\Actions;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\DevicePairingRequest;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
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
            $deviceName = !empty($data['name']) ? $data['name'] : ($pairingRequest->hostname ?? "Device-{$deviceCode}");

            $device = Device::create([
                'name'          => $deviceName,
                'device_code'   => $deviceCode,
                'building_id'   => $data['building_id'],
                'ip_address'    => $pairingRequest->ip_address,
                'fw_version'    => $pairingRequest->engine_version,
                'model_version' => $pairingRequest->model_version ?? config('app.model_version'),
                'status'        => 'offline',
            ]);

            $pairingRequest->update([
                'status'      => 'approved',
                'approved_at' => now(),
                'approved_by' => $admin->id,
                'device_id'   => $device->id,
            ]);

            activity('device')
                ->performedOn($device)
                ->causedBy($admin)
                ->log("Perangkat {$device->name} disetujui via pairing code {$pairingRequest->pairing_code}");

            return $device;
        });
    }
}

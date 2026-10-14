<?php

namespace App\Domain\Device\Actions;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\DevicePairingRequest;
use App\Domain\User\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApproveDevicePairingRequest
{
    public static function generateUniqueDeviceCode(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $latest = DB::table('devices')
                ->where('device_code', 'like', 'dev-%')
                ->orderByRaw('LENGTH(device_code) DESC, device_code DESC')
                ->lockForUpdate()
                ->value('device_code');

            $nextNum = 1;
            if ($latest && preg_match('/^dev-(\d+)$/', $latest, $matches)) {
                $nextNum = ((int) $matches[1]) + 1 + $attempt;
            }

            $code = 'dev-' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);

            if (! DB::table('devices')->where('device_code', $code)->exists()) {
                return $code;
            }
        }

        return 'dev-' . strtoupper(Str::random(6));
    }

    public function execute(DevicePairingRequest $pairingRequest, array $data, User $admin): Device
    {
        if ($pairingRequest->status !== 'pending' || $pairingRequest->isExpired()) {
            throw ValidationException::withMessages([
                'pairing' => ['Permintaan pairing ini sudah tidak valid atau telah kedaluwarsa.'],
            ]);
        }

        return DB::transaction(function () use ($pairingRequest, $data, $admin) {
            $maxAttempts = 5;
            $device = null;

            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                try {
                    $deviceCode = self::generateUniqueDeviceCode();
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
                    break;
                } catch (QueryException $e) {
                    if ($attempt === $maxAttempts - 1) {
                        throw $e;
                    }
                }
            }

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

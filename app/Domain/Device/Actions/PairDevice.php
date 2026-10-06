<?php

namespace App\Domain\Device\Actions;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\PairingCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

class PairDevice
{
    /**
     * Exchange a one-time pairing code for a Sanctum device token.
     *
     * @param  array{code: string, device_name: string, fw_version: string, model_version: string}  $data
     * @throws ValidationException
     */
    public function execute(array $data): array
    {
        $codeHash = hash('sha256', strtoupper($data['code']));

        return DB::transaction(function () use ($data, $codeHash): array {
            $pairingCode = PairingCode::where('code_hash', $codeHash)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $pairingCode || ! $pairingCode->building_id) {
                throw ValidationException::withMessages([
                    'code' => ['Invalid or expired pairing code, or building was removed.'],
                ]);
            }

            // Mark code as used immediately (one-time)
            $pairingCode->update(['used_at' => now()]);

            // Create device record attached to the building the code was created for
            $device = Device::create([
                'building_id'     => $pairingCode->building_id,
                'name'          => $data['device_name'],
                'device_code'   => strtolower((string) Str::ulid()),
                'fw_version'    => $data['fw_version'] ?? '1.0.0',
                'model_version' => config('app.model_version'),
                'status'        => 'online',
                'last_heartbeat_at' => now(),
            ]);

            // Issue Sanctum token with 'device' ability (scoped, can be revoked)
            /** @var NewAccessToken $token */
            $token = $device->createToken('edge-device', ['device']);

            // Store hash of token for revocation lookup (Sanctum stores its own hash too)
            $device->update(['token_hash' => hash('sha256', $token->plainTextToken)]);

            activity('device')
                ->performedOn($device)
                ->log('Device paired successfully');

            return [
                'device_id' => $device->device_code,
                'token'     => $token->plainTextToken, // shown once
                'building_id' => $device->building_id,
                'embed_key' => config('app.enrollment_embed_key', ''),
                'model_version' => config('app.model_version'),
            ];
        });
    }
}

<?php

namespace App\Domain\Device\Actions;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\PairingCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        // Validate the 8-char code (stored as SHA-256 hash)
        $codeHash = hash('sha256', strtoupper($data['code']));

        $pairingCode = PairingCode::where('code_hash', $codeHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $pairingCode) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired pairing code.'],
            ]);
        }

        return DB::transaction(function () use ($data, $pairingCode): array {
            // Mark code as used immediately (one-time)
            $pairingCode->update(['used_at' => now()]);

            // Create device record attached to the branch the code was created for
            $device = Device::create([
                'branch_id'     => $pairingCode->branch_id,
                'name'          => $data['device_name'],
                'device_code'   => 'dev-' . str_pad(Device::count() + 1, 4, '0', STR_PAD_LEFT),
                'fw_version'    => $data['fw_version'],
                'model_version' => $data['model_version'],
                'status'        => 'online',
                'last_heartbeat_at' => now(),
            ]);

            // Issue Sanctum token with 'device' ability (scoped, can be revoked)
            /** @var NewAccessToken $token */
            $token = $device->createToken('edge-device', ['device']);

            // Store hash of token for revocation lookup (Sanctum stores its own hash too)
            $device->update(['token_hash' => hash('sha256', $token->plainTextToken)]);

            return [
                'device_id' => $device->device_code,
                'token'     => $token->plainTextToken, // shown once
                'branch_id' => $device->branch_id,
            ];
        });
    }
}

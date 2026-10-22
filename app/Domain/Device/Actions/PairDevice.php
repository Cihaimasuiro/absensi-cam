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
     * Tukar pairing code sekali pakai dengan token perangkat Sanctum.
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

            // Tandai code sudah terpakai segera (sekali pakai)
            $pairingCode->update(['used_at' => now()]);

            // Buat record perangkat yang terhubung ke building tempat code dibuat
            $device = Device::create([
                'building_id'     => $pairingCode->building_id,
                'name'          => $data['device_name'],
                'device_code'   => strtolower((string) Str::ulid()),
                'fw_version'    => $data['fw_version'] ?? '1.0.0',
                'model_version' => config('app.model_version'),
                'status'        => 'online',
                'last_heartbeat_at' => now(),
            ]);

            // Terbitkan token Sanctum dengan ability 'device' (terbatas, dapat dicabut)
            /** @var NewAccessToken $token */
            $token = $device->createToken('edge-device', ['device']);

            // Simpan hash token untuk pencarian saat pencabutan (Sanctum juga menyimpan hash-nya sendiri)
            $device->update(['token_hash' => hash('sha256', $token->plainTextToken)]);

            activity('device')
                ->performedOn($device)
                ->log('Device paired successfully');

            $embedKey = config('app.enrollment_embed_key');
            if (empty($embedKey)) {
                throw new \RuntimeException('ENROLLMENT_EMBED_KEY is not configured in app config.');
            }

            return [
                'device_id' => $device->device_code,
                'token'     => $token->plainTextToken, // hanya ditampilkan sekali
                'building_id' => $device->building_id,
                'embed_key' => $embedKey,
                'model_version' => config('app.model_version'),
            ];
        });
    }
}

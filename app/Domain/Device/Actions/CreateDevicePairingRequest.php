<?php

namespace App\Domain\Device\Actions;

use App\Domain\Device\Models\DevicePairingRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateDevicePairingRequest
{
    public function execute(array $data, ?string $clientIp = null): array
    {
        $ip = $data['ip_address'] ?? $clientIp;
        $mac = $data['mac_address'] ?? null;

        // Otomatis kedaluwarsakan permintaan pending yang lama
        DevicePairingRequest::where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        // Batasi permintaan pending per IP atau MAC untuk mencegah flooding
        $pendingQuery = DevicePairingRequest::pending();
        if ($mac) {
            $pendingQuery->where(function ($q) use ($ip, $mac) {
                $q->where('mac_address', $mac);
                if ($ip) {
                    $q->orWhere('ip_address', $ip);
                }
            });
        } elseif ($ip) {
            $pendingQuery->where('ip_address', $ip);
        }

        if ($pendingQuery->count() >= 3) {
            throw ValidationException::withMessages([
                'pairing' => ['Terlalu banyak permintaan pairing aktif yang belum disetujui. Tunggu beberapa menit atau setujui permintaan yang sudah ada.'],
            ]);
        }

        // Buat pairing code unik yang mudah dibaca (mis. 782-419)
        do {
            $code = sprintf('%03d-%03d', random_int(100, 999), random_int(100, 999));
        } while (DevicePairingRequest::pending()->where('pairing_code', $code)->exists());

        // Buat poll token berentropi tinggi (64 karakter hex)
        $pollToken = bin2hex(random_bytes(32));
        $pollTokenHash = hash('sha256', $pollToken);

        $request = DevicePairingRequest::create([
            'pairing_code'    => $code,
            'poll_token_hash' => $pollTokenHash,
            'hostname'        => $data['hostname'],
            'mac_address'     => $mac,
            'ip_address'      => $ip,
            'model_version'   => $data['model_version'] ?? null,
            'status'          => 'pending',
            'expires_at'      => now()->addMinutes(10),
        ]);

        return [
            'pairing_code' => $request->pairing_code,
            'poll_token'   => $pollToken,
            'expires_in'   => 600,
            'interval'     => 3,
        ];
    }
}

<?php

namespace App\Domain\Device\Actions;

use App\Domain\Device\Models\DevicePairingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class PollDevicePairing
{
    public function execute(string $pollToken): array
    {
        $pollTokenHash = hash('sha256', $pollToken);

        $request = DevicePairingRequest::where('poll_token_hash', $pollTokenHash)->first();

        if (! $request) {
            return [
                'http_status' => 404,
                'data' => [
                    'status'  => 'not_found',
                    'message' => 'Permintaan pairing tidak ditemukan atau token tidak valid.',
                ],
            ];
        }

        // Otomatis kedaluwarsakan jika melewati batas waktu
        if ($request->isExpired() || $request->status === 'expired') {
            if ($request->status !== 'expired') {
                $request->update(['status' => 'expired']);
            }
            return [
                'http_status' => 410,
                'data' => [
                    'status'  => 'expired',
                    'message' => 'Kode pairing telah kedaluwarsa. Silakan ajukan pairing baru dari perangkat.',
                ],
            ];
        }

        if ($request->status === 'rejected') {
            return [
                'http_status' => 403,
                'data' => [
                    'status'  => 'rejected',
                    'message' => 'Permintaan pairing ditolak oleh administrator.',
                ],
            ];
        }

        if ($request->status === 'claimed') {
            return [
                'http_status' => 410,
                'data' => [
                    'status'  => 'claimed',
                    'message' => 'Kredensial pairing sudah pernah diklaim sebelumnya.',
                ],
            ];
        }

        if ($request->status === 'pending') {
            return [
                'http_status' => 200,
                'data' => [
                    'status'       => 'pending',
                    'pairing_code' => $request->pairing_code,
                    'message'      => 'Menunggu persetujuan administrator di dashboard.',
                ],
            ];
        }

        if ($request->status === 'approved') {
            return DB::transaction(function () use ($request) {
                // Lock ulang record untuk mencegah race condition pada pengambilan sekali pakai
                $locked = DevicePairingRequest::where('id', $request->id)->lockForUpdate()->first();

                if (! $locked || $locked->status !== 'approved' || empty($locked->encrypted_payload)) {
                    return [
                        'http_status' => 410,
                        'data' => [
                            'status'  => 'claimed',
                            'message' => 'Kredensial pairing sudah pernah diklaim sebelumnya.',
                        ],
                    ];
                }

                $payload = json_decode(Crypt::decryptString($locked->encrypted_payload), true);

                // Segera hapus kredensial dari DB (pengiriman sekali pakai)
                $locked->update([
                    'status'            => 'claimed',
                    'claimed_at'        => now(),
                    'encrypted_payload' => null,
                ]);

                return [
                    'http_status' => 200,
                    'data' => array_merge([
                        'status' => 'approved',
                    ], $payload),
                ];
            });
        }

        return [
            'http_status' => 400,
            'data' => [
                'status'  => 'unknown',
                'message' => 'Status permintaan tidak valid.',
            ],
        ];
    }
}

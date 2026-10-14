<?php

namespace App\Domain\Device\Actions;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\DevicePairingRequest;
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

        // Auto expire if past deadline and status is still pending or approved
        if (now()->greaterThan($request->expires_at) && in_array($request->status, ['pending', 'approved'])) {
            $request->update(['status' => 'expired']);
            return [
                'http_status' => 410,
                'data' => [
                    'status'  => 'expired',
                    'message' => 'Permintaan pairing telah kedaluwarsa.',
                ],
            ];
        }

        if ($request->status === 'expired') {
            return [
                'http_status' => 410,
                'data' => [
                    'status'  => 'expired',
                    'message' => 'Permintaan pairing telah kedaluwarsa.',
                ],
            ];
        }

        if ($request->status === 'pending') {
            return [
                'http_status' => 200,
                'data' => [
                    'status'       => 'pending',
                    'pairing_code' => $request->pairing_code,
                    'message'      => 'Menunggu persetujuan admin.',
                ],
            ];
        }

        if ($request->status === 'rejected') {
            return [
                'http_status' => 200,
                'data' => [
                    'status'  => 'rejected',
                    'message' => 'Permintaan pairing ditolak oleh administrator.',
                ],
            ];
        }

        if ($request->status === 'consumed') {
            return [
                'http_status' => 410,
                'data' => [
                    'status'  => 'consumed',
                    'message' => 'Credentials already retrieved',
                ],
            ];
        }

        if ($request->status === 'approved') {
            return DB::transaction(function () use ($request) {
                // Re-lock record to prevent race conditions
                $locked = DevicePairingRequest::where('id', $request->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    return [
                        'http_status' => 404,
                        'data' => [
                            'status'  => 'not_found',
                            'message' => 'Permintaan pairing tidak ditemukan.',
                        ],
                    ];
                }

                if ($locked->status === 'consumed') {
                    return [
                        'http_status' => 410,
                        'data' => [
                            'status'  => 'consumed',
                            'message' => 'Credentials already retrieved',
                        ],
                    ];
                }

                if (now()->greaterThan($locked->expires_at)) {
                    $locked->update(['status' => 'expired']);
                    return [
                        'http_status' => 410,
                        'data' => [
                            'status'  => 'expired',
                            'message' => 'Permintaan pairing telah kedaluwarsa.',
                        ],
                    ];
                }

                if ($locked->status !== 'approved') {
                    return [
                        'http_status' => 200,
                        'data' => [
                            'status' => $locked->status,
                        ],
                    ];
                }

                $device = Device::where('id', $locked->device_id)
                    ->lockForUpdate()
                    ->first();

                if (! $device) {
                    return [
                        'http_status' => 404,
                        'data' => [
                            'status'  => 'device_not_found',
                            'message' => 'Perangkat tertaut tidak ditemukan.',
                        ],
                    ];
                }

                // Issue Sanctum token right now
                $plainToken = $device->createToken('edge-engine', ['device'])->plainTextToken;
                $device->update([
                    'token_hash' => hash('sha256', $plainToken),
                    'status'     => 'online',
                ]);

                // Transition status to consumed
                $locked->update([
                    'status' => 'consumed',
                ]);

                activity('device')
                    ->performedOn($device)
                    ->log("Kredensial pairing ({$locked->pairing_code}) telah diambil oleh perangkat (consumed)");

                $serverUrl = config('app.url') ?: 'http://127.0.0.1:8000';
                
                // Read strictly from config, zero literal fallback
                $embedKey = config('app.enrollment_embed_key');
                if (empty($embedKey)) {
                    throw new \RuntimeException('ENROLLMENT_EMBED_KEY is not configured in config/app.php or environment.');
                }

                $credentials = [
                    'url'           => $serverUrl,
                    'token'         => $plainToken,
                    'device_id'     => (string) $device->id,
                    'device_code'   => $device->device_code,
                    'embed_key'     => $embedKey,
                    'model_version' => $device->model_version,
                ];

                return [
                    'http_status' => 200,
                    'data' => [
                        'status'        => 'approved',
                        'credentials'   => $credentials,
                        'url'           => $serverUrl,
                        'token'         => $plainToken,
                        'device_id'     => (string) $device->id,
                        'device_code'   => $device->device_code,
                        'embed_key'     => $embedKey,
                        'model_version' => $device->model_version,
                    ],
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

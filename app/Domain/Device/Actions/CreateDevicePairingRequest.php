<?php

namespace App\Domain\Device\Actions;

use App\Domain\Device\Models\DevicePairingRequest;

class CreateDevicePairingRequest
{
    public function execute(array $data, ?string $clientIp = null): array
    {
        $serverIp = $clientIp ?? request()->ip();
        $clientReportedIp = $data['ip_address'] ?? null;
        $mac = $data['mac_address'] ?? null;
        $hostname = $data['hostname'];

        // Auto-expire past deadline pending records
        DevicePairingRequest::where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        // Invalidate previous unexpired pending requests from same mac_address or (hostname + serverIp)
        $staleQuery = DevicePairingRequest::where('status', 'pending')
            ->where('expires_at', '>', now());

        if ($mac) {
            $staleQuery->where(function ($q) use ($mac, $hostname, $serverIp) {
                $q->where('mac_address', $mac);
                if ($serverIp) {
                    $q->orWhere(function ($sub) use ($hostname, $serverIp) {
                        $sub->where('hostname', $hostname)->where('ip_address', $serverIp);
                    });
                }
            });
        } elseif ($serverIp) {
            $staleQuery->where('hostname', $hostname)->where('ip_address', $serverIp);
        } else {
            $staleQuery->where('hostname', $hostname);
        }

        $staleQuery->update(['status' => 'expired']);

        // Generate 6-character unambiguous uppercase alphanumeric pairing code (excluding 0, O, 1, I)
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $charLen = strlen($chars);
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, $charLen - 1)];
            }
        } while (DevicePairingRequest::where('pairing_code', $code)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->exists()
        );

        // Generate high-entropy poll token (64 hex characters)
        $pollToken = bin2hex(random_bytes(32));
        $pollTokenHash = hash('sha256', $pollToken);

        $expiresAt = now()->addMinutes(10);

        $request = DevicePairingRequest::create([
            'pairing_code'        => $code,
            'poll_token_hash'     => $pollTokenHash,
            'hostname'            => $hostname,
            'mac_address'         => $mac,
            'ip_address'          => $serverIp,
            'client_reported_ip'  => $clientReportedIp,
            'engine_version'      => $data['engine_version'] ?? $data['model_version'] ?? null,
            'model_version'       => $data['model_version'] ?? null,
            'status'              => 'pending',
            'expires_at'          => $expiresAt,
        ]);

        return [
            'pairing_code'      => $request->pairing_code,
            'poll_token'        => $pollToken,
            'expires_at'        => $request->expires_at->toIso8601ZuluString(),
            'poll_interval_sec' => 3,
            'expires_in'        => 600,
            'interval'          => 3,
        ];
    }
}

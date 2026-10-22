<?php

namespace App\Domain\Device\Actions;

use App\Domain\Device\Models\Device;

class RecordHeartbeat
{
    /**
     * Perbarui telemetri perangkat dari payload heartbeat.
     *
     * @param  array{fw_version: string, model_version: string, cpu_temp: float, ram_free_mb: int,
     *               disk_free_mb: int, fps: float, outbox_len: int, serial_ok: bool, ip_address: ?string}  $data
     */
    public function execute(Device $device, array $data): void
    {
        $ip = $this->resolveDeviceIp($data['ip_address'] ?? null, $device->ip_address);

        $updateData = [
            'fw_version'        => $data['fw_version'],
            'model_version'     => $data['model_version'],
            'last_cpu_temp'     => $data['cpu_temp'],
            'last_outbox_len'   => $data['outbox_len'],
            'last_heartbeat_at' => now(),
            'ip_address'        => $ip,
            'status'            => 'online',
        ];

        if (array_key_exists('quarantined_templates_count', $data) && $data['quarantined_templates_count'] !== null) {
            $updateData['quarantined_templates_count'] = (int) $data['quarantined_templates_count'];
        }

        $device->update($updateData);
    }

    private function resolveDeviceIp(?string $reportedIp, ?string $fallbackIp): ?string
    {
        if ($reportedIp && !in_array($reportedIp, ['127.0.0.1', '::1'], true)) {
            return $reportedIp;
        }

        $remoteIp = request()->ip();
        if ($remoteIp && !in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
            return $remoteIp;
        }

        return $fallbackIp;
    }
}

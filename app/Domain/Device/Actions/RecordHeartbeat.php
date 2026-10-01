<?php

namespace App\Domain\Device\Actions;

use App\Domain\Device\Models\Device;

class RecordHeartbeat
{
    /**
     * Update device telemetry from heartbeat payload.
     *
     * @param  array{fw_version: string, model_version: string, cpu_temp: float, ram_free_mb: int,
     *               disk_free_mb: int, fps: float, outbox_len: int, serial_ok: bool, ip_address: ?string}  $data
     */
    public function execute(Device $device, array $data): void
    {
        $device->update([
            'fw_version'        => $data['fw_version'],
            'model_version'     => $data['model_version'],
            'last_cpu_temp'     => $data['cpu_temp'],
            'last_outbox_len'   => $data['outbox_len'],
            'last_heartbeat_at' => now(),
            'ip_address'        => $data['ip_address'] ?? $device->ip_address,
            'status'            => 'online',
        ]);
    }
}

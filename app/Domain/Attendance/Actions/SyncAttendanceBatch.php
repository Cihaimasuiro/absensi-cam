<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Device\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncAttendanceBatch
{
    /**
     * Idempotently insert a batch of attendance records from an edge device.
     *
     * Returns per-record results: created | duplicate | rejected.
     *
     * @param  array<array{id: string, member_id: string, captured_at: string,
     *                     direction: string, score: float, liveness_score: ?float,
     *                     time_source: string}>  $records
     * @return array<array{id: string, status: string, reason?: string}>
     */
    public function execute(Device $device, array $records): array
    {
        $results = [];

        // Process each record in its own small transaction for true idempotency:
        // if the whole batch wrapped in one TX failed midway, edge would retry
        // and we'd get duplicates. Per-record TX means only the failed ones need retry.
        foreach ($records as $record) {
            try {
                $inserted = DB::transaction(function () use ($device, $record): bool {
                    // UUID from edge is the PK — insertOrIgnore handles the duplicate case
                    $affected = AttendanceLog::insertOrIgnore([[
                        'id'             => $record['id'],
                        'member_id'      => $record['member_id'],
                        'device_id'      => $device->id,
                        'captured_at'    => $record['captured_at'],
                        'direction'      => $record['direction'],
                        'score'          => $record['score'],
                        'liveness_score' => $record['liveness_score'] ?? null,
                        'time_source'    => $record['time_source'],
                        'received_at'    => now(),
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]]);

                    return $affected > 0;
                });

                $results[] = [
                    'id'     => $record['id'],
                    'status' => $inserted ? 'created' : 'duplicate',
                ];
            } catch (\Throwable $e) {
                Log::warning('attendance_batch_record_failed', [
                    'record_id' => $record['id'],
                    'device'    => $device->device_code,
                    'error'     => $e->getMessage(),
                ]);

                $results[] = [
                    'id'     => $record['id'],
                    'status' => 'rejected',
                    'reason' => 'server_error',
                ];
            }
        }

        return $results;
    }
}

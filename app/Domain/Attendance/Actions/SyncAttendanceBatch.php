<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Device\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncAttendanceBatch
{
    /**
     * Sisipkan satu batch catatan absensi dari perangkat edge secara idempoten.
     *
     * Mengembalikan hasil per record: created | duplicate | rejected.
     *
     * @param  array<array{id: string, student_id: string, captured_at: string,
     *                     direction: string, score: float, liveness_score: ?float,
     *                     time_source: string}>  $records
     * @return array<array{id: string, status: string, reason?: string}>
     */
    public function execute(Device $device, array $records): array
    {
        $results = [];

        // Proses setiap record dalam transaksi kecil tersendiri agar benar-benar idempoten:
        // jika seluruh batch dibungkus satu TX lalu gagal di tengah, edge akan mengulang
        // dan kita mendapat duplikat. Dengan TX per record, hanya yang gagal perlu diulang.
        foreach ($records as $record) {
            try {
                $inserted = DB::transaction(function () use ($device, $record): bool {
                    // UUID dari edge adalah PK — insertOrIgnore menangani kasus duplikat
                    $affected = AttendanceLog::insertOrIgnore([[
                        'id'             => $record['id'],
                        'student_id'      => $record['student_id'],
                        'device_id'      => $device->id,
                        'captured_at'    => \Illuminate\Support\Carbon::parse($record['captured_at'])
                                                ->setTimezone('UTC')
                                                ->format('Y-m-d H:i:s'),
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

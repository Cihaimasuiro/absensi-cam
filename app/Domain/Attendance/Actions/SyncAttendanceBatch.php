<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Device\Models\Device;
use App\Domain\Student\Models\Student;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
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

        foreach ($records as $record) {
            $recordId = $record['id'];

            // 1. Cek duplikat awal berdasarkan ID unik edge
            if (AttendanceLog::where('id', $recordId)->exists()) {
                $results[] = [
                    'id'     => $recordId,
                    'status' => 'duplicate',
                ];
                continue;
            }

            // 2. Validasi keberadaan dan scope siswa
            $student = Student::withTrashed()->find($record['student_id']);
            if (!$student) {
                Log::warning('attendance_sync_unknown_student', [
                    'record_id'  => $recordId,
                    'student_id' => $record['student_id'],
                    'device'     => $device->device_code,
                ]);

                $results[] = [
                    'id'     => $recordId,
                    'status' => 'rejected',
                    'reason' => 'unknown_student',
                ];
                continue;
            }

            $schoolId = $device->school_id ?? $device->building?->school_id;
            if ($schoolId && $student->school_id && $student->school_id !== $schoolId) {
                Log::warning('attendance_sync_student_scope_mismatch', [
                    'record_id'      => $recordId,
                    'student_id'     => $record['student_id'],
                    'student_school' => $student->school_id,
                    'device_school'  => $schoolId,
                ]);

                $results[] = [
                    'id'     => $recordId,
                    'status' => 'rejected',
                    'reason' => 'scope_mismatch',
                ];
                continue;
            }

            // 3. Validasi kewajaran waktu absensi
            try {
                $capturedAt = Carbon::parse($record['captured_at'])->setTimezone('UTC');
                if ($capturedAt->isAfter(now()->addDay()) || $capturedAt->year < 2020) {
                    $results[] = [
                        'id'     => $recordId,
                        'status' => 'rejected',
                        'reason' => 'invalid_timestamp',
                    ];
                    continue;
                }
            } catch (\Throwable) {
                $results[] = [
                    'id'     => $recordId,
                    'status' => 'rejected',
                    'reason' => 'malformed_timestamp',
                ];
                continue;
            }

            // 4. Penyisipan transaksi per-record dengan penanganan unique race
            try {
                DB::transaction(function () use ($device, $record, $capturedAt) {
                    AttendanceLog::create([
                        'id'             => $record['id'],
                        'student_id'     => $record['student_id'],
                        'device_id'      => $device->id,
                        'captured_at'    => $capturedAt->format('Y-m-d H:i:s'),
                        'direction'      => in_array($record['direction'] ?? 'in', ['in', 'out']) ? $record['direction'] : 'in',
                        'score'          => $record['score'] ?? 0.5,
                        'liveness_score' => $record['liveness_score'] ?? null,
                        'time_source'    => $record['time_source'] ?? 'ntp',
                        'received_at'    => now(),
                    ]);
                });

                $results[] = [
                    'id'     => $recordId,
                    'status' => 'created',
                ];
            } catch (UniqueConstraintViolationException) {
                $results[] = [
                    'id'     => $recordId,
                    'status' => 'duplicate',
                ];
            } catch (\Throwable $e) {
                Log::warning('attendance_batch_record_failed', [
                    'record_id' => $recordId,
                    'device'    => $device->device_code,
                    'error'     => $e->getMessage(),
                ]);

                $results[] = [
                    'id'     => $recordId,
                    'status' => 'rejected',
                    'reason' => 'server_error',
                ];
            }
        }

        return $results;
    }
}

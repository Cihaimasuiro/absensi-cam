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
     * Sisipkan satu batch catatan absensi dari perangkat edge secara idempoten dan efisien.
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

        if (empty($records)) {
            return [];
        }

        // 1. Prefetching massal untuk memangkas ratusan query per batch menjadi 2 query efisien
        $recordIds = array_column($records, 'id');
        $studentIds = array_values(array_filter(array_unique(array_column($records, 'student_id'))));

        $existingLogsMap = AttendanceLog::whereIn('id', $recordIds)->pluck('id')->flip()->toArray();
        $studentsMap = Student::withTrashed()->whereIn('id', $studentIds)->get()->keyBy('id');

        $deviceSchoolId = (int) ($device->school_id ?? $device->building?->school_id);

        foreach ($records as $record) {
            $recordId = $record['id'];

            // 2. Cek duplikat cepat dalam memori
            if (isset($existingLogsMap[$recordId])) {
                $results[] = [
                    'id'     => $recordId,
                    'status' => 'duplicate',
                ];
                continue;
            }

            // 3. Validasi keberadaan siswa
            /** @var ?Student $student */
            $student = $studentsMap->get($record['student_id']);
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

            // 4. Validasi scope sekolah dengan type-casting integer tegas
            if ($deviceSchoolId > 0) {
                $studentSchoolId = (int) $student->school_id;
                if ($studentSchoolId !== $deviceSchoolId) {
                    Log::warning('attendance_sync_student_scope_mismatch', [
                        'record_id'      => $recordId,
                        'student_id'     => $record['student_id'],
                        'student_school' => $studentSchoolId,
                        'device_school'  => $deviceSchoolId,
                    ]);

                    $results[] = [
                        'id'     => $recordId,
                        'status' => 'rejected',
                        'reason' => 'scope_mismatch',
                    ];
                    continue;
                }
            }

            // 5. Validasi waktu absensi dan toleransi jam boot terminal (unsynced clock)
            $timeSource = $record['time_source'] ?? 'ntp';
            try {
                $capturedAt = Carbon::parse($record['captured_at'])->setTimezone('UTC');

                if ($capturedAt->isAfter(now()->addDay())) {
                    $results[] = [
                        'id'     => $recordId,
                        'status' => 'rejected',
                        'reason' => 'invalid_timestamp',
                    ];
                    continue;
                }

                if ($capturedAt->year < 2020) {
                    // Jika terminal belum sinkron RTC/NTP saat boot, terima data dan tandai time_source=unsynced
                    // agar absensi kehadiran fisik siswa tidak dibuang permanen
                    if ($timeSource === 'unsynced') {
                        Log::info('attendance_sync_unsynced_boot_clock_accepted', [
                            'record_id'   => $recordId,
                            'captured_at' => $capturedAt->toIso8601String(),
                            'device'      => $device->device_code,
                        ]);
                    } else {
                        $results[] = [
                            'id'     => $recordId,
                            'status' => 'rejected',
                            'reason' => 'invalid_timestamp',
                        ];
                        continue;
                    }
                }
            } catch (\Throwable) {
                $results[] = [
                    'id'     => $recordId,
                    'status' => 'rejected',
                    'reason' => 'malformed_timestamp',
                ];
                continue;
            }

            // 6. Penyisipan transaksi per-record dengan penanganan race condition unik
            try {
                DB::transaction(function () use ($device, $record, $capturedAt, $timeSource) {
                    AttendanceLog::create([
                        'id'             => $record['id'],
                        'student_id'     => $record['student_id'],
                        'device_id'      => $device->id,
                        'captured_at'    => $capturedAt->format('Y-m-d H:i:s'),
                        'direction'      => in_array($record['direction'] ?? 'in', ['in', 'out'], true) ? $record['direction'] : 'in',
                        'score'          => $record['score'] ?? 0.5,
                        'liveness_score' => $record['liveness_score'] ?? null,
                        'time_source'    => $timeSource,
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

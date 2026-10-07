<?php

namespace App\Domain\Report\Services;

use Illuminate\Support\Collection;
use Carbon\Carbon;

class DtrService
{
    public function generateDtr(iterable $logs): Collection
    {
        // $logs already have captures_at casted to app timezone by accessor
        
        $dtr = [];
        
        foreach ($logs as $log) {
            if (!$log->student) {
                continue;
            }
            
            $date = $log->captured_at->toDateString();
            $studentId = $log->student_id;
            $key = "{$studentId}_{$date}";
            
            if (!isset($dtr[$key])) {
                $dtr[$key] = [
                    'date' => $date,
                    'name' => $log->student->name,
                    'code' => $log->student->code,
                    'group' => $log->student->group ? $log->student->group->name : '-',
                    'first_in' => null,
                    'last_out' => null,
                    'is_corrected' => false,
                    'start_time' => $log->student->group ? $log->student->group->start_time : '07:00:00',
                    'late_tolerance_minutes' => $log->student->group ? $log->student->group->late_tolerance_minutes : 15,
                ];
            }
            
            if ($log->is_corrected) {
                $dtr[$key]['is_corrected'] = true;
            }
            
            $timeStr = $log->captured_at->toTimeString();
            
            if ($log->direction === 'in') {
                if ($dtr[$key]['first_in'] === null || $timeStr < $dtr[$key]['first_in']) {
                    $dtr[$key]['first_in'] = $timeStr;
                }
            } elseif ($log->direction === 'out') {
                if ($dtr[$key]['last_out'] === null || $timeStr > $dtr[$key]['last_out']) {
                    $dtr[$key]['last_out'] = $timeStr;
                }
            }
        }
        
        $results = [];
        foreach ($dtr as $record) {
            $firstIn = $record['first_in'];
            $lastOut = $record['last_out'];
            
            $durationMins = 0;
            if ($firstIn && $lastOut) {
                $inTime = Carbon::parse($record['date'] . ' ' . $firstIn);
                $outTime = Carbon::parse($record['date'] . ' ' . $lastOut);
                $durationMins = (int) $inTime->diffInMinutes($outTime, true);
            }
            
            $minutesLate = 0;
            $status = 'Hadir';
            
            if ($firstIn) {
                $expectedStart = Carbon::parse($record['date'] . ' ' . $record['start_time']);
                $actualIn = Carbon::parse($record['date'] . ' ' . $firstIn);
                
                if ($actualIn->greaterThan($expectedStart)) {
                    $diff = (int) $expectedStart->diffInMinutes($actualIn, true);
                    if ($record['late_tolerance_minutes'] !== null && $diff > $record['late_tolerance_minutes']) {
                        $minutesLate = $diff;
                        $status = 'Terlambat';
                    }
                }
            } else {
                $status = 'Tanpa Scan Masuk';
            }
            
            $results[] = [
                'date' => $record['date'],
                'name' => $record['name'],
                'code' => $record['code'],
                'group' => $record['group'],
                'first_in' => $firstIn ?? '-',
                'last_out' => $lastOut ?? '-',
                'duration_minutes' => $durationMins,
                'minutes_late' => $minutesLate,
                'status' => $status,
                'is_corrected' => $record['is_corrected'],
            ];
        }
        
        // Sort by date then name
        usort($results, function($a, $b) {
            if ($a['date'] === $b['date']) {
                return strcmp($a['name'], $b['name']);
            }
            return strcmp($a['date'], $b['date']);
        });
        
        return collect($results);
    }
}

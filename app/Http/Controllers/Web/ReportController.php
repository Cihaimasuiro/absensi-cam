<?php

namespace App\Http\Controllers\Web;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Student\Models\Student;
use App\Domain\School\Models\Classroom;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $start   = $request->input('start', today()->toDateString());
        $end     = $request->input('end',   today()->toDateString());
        $groupId = $request->input('classroom_id');

        $logs = AttendanceLog::with(['student:id,name,code,classroom_id', 'student.group:id,name', 'device:id,name'])
            ->whereDate('captured_at', '>=', $start)
            ->whereDate('captured_at', '<=', $end)
            ->when($groupId, fn ($q) =>
                $q->whereHas('student', fn ($q) => $q->where('classroom_id', $groupId))
            )
            ->select(['id', 'student_id', 'device_id', 'captured_at', 'direction', 'score', 'liveness_score', 'is_corrected', 'time_source'])
            ->orderBy('captured_at')
            ->paginate(100)
            ->withQueryString();

        $classrooms = Classroom::active()->select(['id', 'name'])->orderBy('name')->get();

        return view('reports.index', compact('logs', 'classrooms', 'start', 'end', 'groupId'));
    }

    public function exportCsv(Request $request)
    {
        $start   = $request->input('start', today()->toDateString());
        $end     = $request->input('end',   today()->toDateString());
        $groupId = $request->input('classroom_id');

        $logs = AttendanceLog::with(['student:id,name,code', 'device:id,name'])
            ->whereDate('captured_at', '>=', $start)
            ->whereDate('captured_at', '<=', $end)
            ->when($groupId, fn ($q) =>
                $q->whereHas('student', fn ($q) => $q->where('classroom_id', $groupId))
            )
            ->orderBy('captured_at')
            ->get(['id', 'student_id', 'device_id', 'captured_at', 'direction', 'score', 'is_corrected']);

        $filename = "absensi_{$start}_{$end}.csv";
        $headers  = ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename={$filename}"];

        $callback = function () use ($logs) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Nama', 'Kode', 'Perangkat', 'Waktu', 'Arah', 'Skor', 'Dikoreksi']);
            foreach ($logs as $log) {
                // Prevent CSV injection
                $sanitize = function($val) {
                    return preg_match('/^[=\-+\@]/', (string)$val) ? "'" . $val : $val;
                };
                
                fputcsv($out, [
                    $log->id,
                    $sanitize($log->student?->name ?? '-'),
                    $sanitize($log->student?->code ?? '-'),
                    $sanitize($log->device?->name ?? '-'),
                    $log->captured_at->toDateTimeString(),
                    $log->direction,
                    number_format($log->score, 4),
                    $log->is_corrected ? 'Ya' : 'Tidak',
                ]);
            }
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }
}

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

        $startUtc = \Carbon\Carbon::parse($start)->startOfDay()->setTimezone('UTC');
        $endUtc = \Carbon\Carbon::parse($end)->endOfDay()->setTimezone('UTC');

        $logs = AttendanceLog::with(['student:id,name,code,classroom_id', 'student.group:id,name', 'device:id,name'])
            ->whereBetween('captured_at', [$startUtc, $endUtc])
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

        $startUtc = \Carbon\Carbon::parse($start)->startOfDay()->setTimezone('UTC');
        $endUtc = \Carbon\Carbon::parse($end)->endOfDay()->setTimezone('UTC');

        $logs = AttendanceLog::with(['student:id,name,code', 'device:id,name'])
            ->whereBetween('captured_at', [$startUtc, $endUtc])
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

        activity('report')
            ->causedBy(auth()->user())
            ->withProperties(['start' => $start, 'end' => $end, 'classroom_id' => $groupId])
            ->log('Exported CSV report');

        return response()->stream($callback, 200, $headers);
    }

    private function getDtrRecords(Request $request, \App\Domain\Report\Services\DtrService $dtrService, &$start, &$end, &$groupId)
    {
        $request->validate([
            'start' => ['nullable', 'date'],
            'end'   => ['nullable', 'date', 'after_or_equal:start'],
        ]);

        $start   = $request->input('start', today()->toDateString());
        $end     = $request->input('end',   today()->toDateString());
        $groupId = $request->input('classroom_id');

        $startC = \Carbon\Carbon::parse($start);
        $endC   = \Carbon\Carbon::parse($end);

        if ($startC->diffInDays($endC) >= 31) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'end' => ['Rentang tanggal maksimal 31 hari.'],
            ]);
        }

        $startUtc = $startC->startOfDay()->setTimezone('UTC');
        $endUtc   = $endC->endOfDay()->setTimezone('UTC');

        $logsLazy = AttendanceLog::with(['student:id,name,code,classroom_id', 'student.group:id,name,start_time,late_tolerance_minutes'])
            ->whereBetween('captured_at', [$startUtc, $endUtc])
            ->when($groupId, fn ($q) =>
                $q->whereHas('student', fn ($q) => $q->where('classroom_id', $groupId))
            )
            ->lazyById(1000);

        return $dtrService->generateDtr($logsLazy);
    }

    public function exportDtr(Request $request, \App\Domain\Report\Services\DtrService $dtrService)
    {
        $dtrRecords = $this->getDtrRecords($request, $dtrService, $start, $end, $groupId);

        activity('report')
            ->causedBy(auth()->user())
            ->withProperties(['start' => $start, 'end' => $end, 'classroom_id' => $groupId])
            ->log('Exported DTR Excel');

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Domain\Report\Exports\DtrExport($dtrRecords),
            "dtr_{$start}_{$end}.xlsx"
        );
    }

    public function exportDtrPdf(Request $request, \App\Domain\Report\Services\DtrService $dtrService)
    {
        $dtrRecords = $this->getDtrRecords($request, $dtrService, $start, $end, $groupId);

        activity('report')
            ->causedBy(auth()->user())
            ->withProperties(['start' => $start, 'end' => $end, 'classroom_id' => $groupId])
            ->log('Exported DTR PDF');

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.dtr_pdf', [
            'records' => $dtrRecords,
            'start' => $start,
            'end' => $end,
        ])->setPaper('a4', 'landscape');

        return $pdf->download("dtr_{$start}_{$end}.pdf");
    }
}

<?php

namespace App\Http\Controllers\Web;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Member\Models\Member;
use App\Domain\Organization\Models\Group;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $start   = $request->input('start', today()->toDateString());
        $end     = $request->input('end',   today()->toDateString());
        $groupId = $request->input('group_id');

        $logs = AttendanceLog::with(['member:id,name,code,group_id', 'member.group:id,name', 'device:id,name'])
            ->whereDate('captured_at', '>=', $start)
            ->whereDate('captured_at', '<=', $end)
            ->when($groupId, fn ($q) =>
                $q->whereHas('member', fn ($q) => $q->where('group_id', $groupId))
            )
            ->select(['id', 'member_id', 'device_id', 'captured_at', 'direction', 'score', 'liveness_score', 'is_corrected', 'time_source'])
            ->orderBy('captured_at')
            ->paginate(100)
            ->withQueryString();

        $groups = Group::active()->select(['id', 'name'])->orderBy('name')->get();

        return view('reports.index', compact('logs', 'groups', 'start', 'end', 'groupId'));
    }

    public function exportCsv(Request $request)
    {
        $start   = $request->input('start', today()->toDateString());
        $end     = $request->input('end',   today()->toDateString());
        $groupId = $request->input('group_id');

        $logs = AttendanceLog::with(['member:id,name,code', 'device:id,name'])
            ->whereDate('captured_at', '>=', $start)
            ->whereDate('captured_at', '<=', $end)
            ->when($groupId, fn ($q) =>
                $q->whereHas('member', fn ($q) => $q->where('group_id', $groupId))
            )
            ->orderBy('captured_at')
            ->get(['id', 'member_id', 'device_id', 'captured_at', 'direction', 'score', 'is_corrected']);

        $filename = "absensi_{$start}_{$end}.csv";
        $headers  = ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename={$filename}"];

        $callback = function () use ($logs) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Nama', 'Kode', 'Perangkat', 'Waktu', 'Arah', 'Skor', 'Dikoreksi']);
            foreach ($logs as $log) {
                fputcsv($out, [
                    $log->id,
                    $log->member?->name ?? '-',
                    $log->member?->code ?? '-',
                    $log->device?->name ?? '-',
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

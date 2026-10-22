<?php

namespace App\Http\Controllers\Web;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Device\Models\Device;
use App\Domain\Student\Models\Student;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = today();
        $startUtc = $today->copy()->startOfDay()->setTimezone('UTC');
        $endUtc = $today->copy()->endOfDay()->setTimezone('UTC');

        // Statistik (di-port dari Facenox Overview.tsx fetchOverviewData)
        $totalStudents    = Student::where('is_active', true)->count();
        $totalEnrolled   = Student::whereHas('faceTemplate')->count();
        $presentToday    = AttendanceLog::where('direction', 'in')
            ->whereBetween('captured_at', [$startUtc, $endUtc])->distinct('student_id')->count('student_id');
        $devicesOnline   = Device::where('status', 'online')
            ->where('last_heartbeat_at', '>=', now()->subMinutes(3))->count();

        // Aktivitas terbaru (30 event terakhir hari ini — mencerminkan panel live log di Overview.tsx)
        $recentActivity = AttendanceLog::with(['student:id,name,code', 'device:id,name,device_code'])
            ->whereBetween('captured_at', [$startUtc, $endUtc])
            ->orderByDesc('captured_at')
            ->limit(30)
            ->get(['id', 'student_id', 'device_id', 'captured_at', 'direction', 'score']);

        $streamDevice = Device::where('status', 'online')->latest('last_heartbeat_at')->first();
        $streamIp = $streamDevice && $streamDevice->ip_address ? $streamDevice->ip_address : '127.0.0.1';
        $streamName = $streamDevice ? $streamDevice->name : 'Local Edge Engine';
        
        $devices = Device::orderBy('name')->get(['id', 'name', 'ip_address']);

        $timezone = config('app.timezone', 'Asia/Jakarta');
        $recentLogs = $recentActivity->map(fn($log) => $this->formatLogItem($log, $timezone))->values();

        return view('live-camera.index', compact(
            'totalStudents', 'totalEnrolled', 'presentToday', 'devicesOnline', 'recentLogs', 'streamIp', 'streamName', 'devices'
        ));
    }

    public function recentLogs(Request $request)
    {
        $today = today();
        $startUtc = $today->copy()->startOfDay()->setTimezone('UTC');
        $endUtc = $today->copy()->endOfDay()->setTimezone('UTC');

        $presentToday = AttendanceLog::where('direction', 'in')
            ->whereBetween('captured_at', [$startUtc, $endUtc])
            ->distinct('student_id')
            ->count('student_id');

        $logs = AttendanceLog::with(['student:id,name,code', 'device:id,name,device_code'])
            ->whereBetween('captured_at', [$startUtc, $endUtc])
            ->orderByDesc('captured_at')
            ->limit(30)
            ->get(['id', 'student_id', 'device_id', 'captured_at', 'direction', 'score']);

        $timezone = config('app.timezone', 'Asia/Jakarta');

        return response()->json([
            'present_today' => $presentToday,
            'logs' => $logs->map(fn($log) => $this->formatLogItem($log, $timezone))->values(),
        ]);
    }

    private function formatLogItem($log, string $timezone): array
    {
        return [
            'id' => $log->id,
            'name' => $log->student->name ?? 'Tidak Dikenal',
            'code' => $log->student->code ?? '-',
            'initial' => mb_strtoupper(mb_substr($log->student->name ?? '?', 0, 1)),
            'direction' => $log->direction,
            'time' => $log->captured_at ? $log->captured_at->timezone($timezone)->format('H:i:s') : '--:--:--',
            'score' => $log->score ? round($log->score * 100) . '%' : null,
            'device' => $log->device->name ?? null,
        ];
    }
}

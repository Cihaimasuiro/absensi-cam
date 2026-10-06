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

        // Stats (ported from Facenox Overview.tsx fetchOverviewData)
        $totalStudents    = Student::where('is_active', true)->count();
        $totalEnrolled   = Student::whereHas('faceTemplate')->count();
        $presentToday    = AttendanceLog::where('direction', 'in')
            ->whereBetween('captured_at', [$startUtc, $endUtc])->distinct('student_id')->count('student_id');
        $devicesOnline   = Device::where('status', 'online')
            ->where('last_heartbeat_at', '>=', now()->subMinutes(3))->count();

        // Recent activity (last 30 events today — mirrors live log panel in Overview.tsx)
        $recentActivity = AttendanceLog::with(['student:id,name,code', 'device:id,name,device_code'])
            ->whereBetween('captured_at', [$startUtc, $endUtc])
            ->orderByDesc('captured_at')
            ->limit(30)
            ->get(['id', 'student_id', 'device_id', 'captured_at', 'direction', 'score']);

        $streamDevice = Device::where('status', 'online')->latest('last_heartbeat_at')->first();
        $streamIp = $streamDevice && $streamDevice->ip_address ? $streamDevice->ip_address : '127.0.0.1';
        $streamName = $streamDevice ? $streamDevice->name : 'Local Edge Engine';

        return view('dashboard.index', compact(
            'totalStudents', 'totalEnrolled', 'presentToday', 'devicesOnline', 'recentActivity', 'streamIp', 'streamName'
        ));
    }
}

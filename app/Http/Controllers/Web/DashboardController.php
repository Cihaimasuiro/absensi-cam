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

        // Stats (ported from Facenox Overview.tsx fetchOverviewData)
        $totalStudents    = Student::where('is_active', true)->count();
        $totalEnrolled   = Student::whereHas('faceTemplate')->count();
        $presentToday    = AttendanceLog::where('direction', 'in')
            ->whereDate('captured_at', $today)->distinct('student_id')->count('student_id');
        $devicesOnline   = Device::where('status', 'online')
            ->where('last_heartbeat_at', '>=', now()->subMinutes(3))->count();

        // Recent activity (last 30 events today — mirrors live log panel in Overview.tsx)
        $recentActivity = AttendanceLog::with(['student:id,name,code', 'device:id,name,device_code'])
            ->whereDate('captured_at', $today)
            ->orderByDesc('captured_at')
            ->limit(30)
            ->get(['id', 'student_id', 'device_id', 'captured_at', 'direction', 'score']);

        return view('dashboard.index', compact(
            'totalStudents', 'totalEnrolled', 'presentToday', 'devicesOnline', 'recentActivity'
        ));
    }
}

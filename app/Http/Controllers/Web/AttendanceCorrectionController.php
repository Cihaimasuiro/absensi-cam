<?php

namespace App\Http\Controllers\Web;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AttendanceCorrectionController extends Controller
{
    public function store(Request $request, $id)
    {
        $validated = $request->validate([
            'reason'                => ['required', 'string', 'max:255'],
            'corrected_captured_at' => ['required', 'date', 'after_or_equal:' . now()->subDays(14)->toDateString()],
            'corrected_direction'   => ['required', 'in:in,out'],
        ]);

        DB::transaction(function () use ($validated, $id) {
            $log = AttendanceLog::where('id', $id)->lockForUpdate()->firstOrFail();
            
            if ($log->is_corrected) {
                abort(422, 'Log ini sudah pernah dikoreksi sebelumnya.');
            }

            AttendanceCorrection::create([
                'attendance_log_id'    => $log->id,
                'corrected_by'         => auth()->id(),
                'reason'               => $validated['reason'],
                'original_captured_at' => clone $log->captured_at,
                'corrected_captured_at'=> Carbon::parse($validated['corrected_captured_at'], config('app.timezone')),
                'original_direction'   => $log->direction,
                'corrected_direction'  => $validated['corrected_direction'],
            ]);

            $log->update([
                'captured_at'  => Carbon::parse($validated['corrected_captured_at'], config('app.timezone')),
                'direction'    => $validated['corrected_direction'],
                'is_corrected' => true,
            ]);

            activity()
                ->performedOn($log)
                ->causedBy(auth()->user())
                ->withProperties(['reason' => $validated['reason']])
                ->log('corrected_attendance');
        });

        return back()->with('success', 'Attendance log corrected successfully.');
    }
}

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
    public function store(Request $request, AttendanceLog $log)
    {
        $validated = $request->validate([
            'reason'                => ['required', 'string', 'max:255'],
            'corrected_captured_at' => ['required', 'date'],
            'corrected_direction'   => ['required', 'in:in,out'],
        ]);

        DB::transaction(function () use ($validated, $log) {
            AttendanceCorrection::create([
                'attendance_log_id'    => $log->id,
                'corrected_by'         => auth()->id(),
                'reason'               => $validated['reason'],
                'original_captured_at' => $log->captured_at,
                'corrected_captured_at'=> Carbon::parse($validated['corrected_captured_at'], config('app.timezone')),
                'original_direction'   => $log->direction,
                'corrected_direction'  => $validated['corrected_direction'],
            ]);

            $log->update([
                'captured_at'  => Carbon::parse($validated['corrected_captured_at'], config('app.timezone')),
                'direction'    => $validated['corrected_direction'],
                'is_corrected' => true,
            ]);
        });

        return back()->with('success', 'Attendance log corrected successfully.');
    }
}

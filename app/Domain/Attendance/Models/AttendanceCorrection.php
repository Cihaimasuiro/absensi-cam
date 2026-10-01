<?php

namespace App\Domain\Attendance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrection extends Model
{
    protected $fillable = [
        'attendance_log_id',
        'corrected_by',
        'reason',
        'original_captured_at',
        'corrected_captured_at',
        'original_direction',
        'corrected_direction',
    ];

    protected $casts = [
        'original_captured_at'   => 'datetime',
        'corrected_captured_at'  => 'datetime',
    ];

    public function attendanceLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class);
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}

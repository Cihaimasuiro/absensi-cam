<?php

namespace App\Domain\Attendance\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrection extends Model
{
    use HasFactory;
    protected $fillable = [
        'attendance_log_id',
        'corrected_by',
        'reason',
        'original_captured_at',
        'corrected_captured_at',
        'original_direction',
        'corrected_direction',
    ];

    protected $casts = [];

    protected function originalCapturedAt(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value, 'UTC')->setTimezone(config('app.timezone')) : null,
            set: fn ($value) => $value instanceof \Carbon\CarbonInterface 
                ? $value->copy()->setTimezone('UTC')->format('Y-m-d H:i:s') 
                : ($value ? \Illuminate\Support\Carbon::parse($value, 'UTC')->format('Y-m-d H:i:s') : null),
        );
    }

    protected function correctedCapturedAt(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value, 'UTC')->setTimezone(config('app.timezone')) : null,
            set: fn ($value) => $value instanceof \Carbon\CarbonInterface 
                ? $value->copy()->setTimezone('UTC')->format('Y-m-d H:i:s') 
                : ($value ? \Illuminate\Support\Carbon::parse($value, 'UTC')->format('Y-m-d H:i:s') : null),
        );
    }

    public function attendanceLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class);
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}



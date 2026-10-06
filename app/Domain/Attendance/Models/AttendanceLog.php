<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Device\Models\Device;
use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttendanceLog extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'id', // UUID from edge — must be explicitly allowed for idempotent insert
        'student_id',
        'device_id',
        'captured_at',
        'direction',
        'score',
        'liveness_score',
        'time_source',
        'received_at',
        'is_corrected',
    ];

    protected $casts = [
        'received_at'  => 'datetime',
        'score'        => 'float',
        'liveness_score' => 'float',
        'is_corrected' => 'boolean',
    ];

    protected function capturedAt(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value, 'UTC')->setTimezone(config('app.timezone')) : null,
            set: fn ($value) => $value instanceof \Carbon\CarbonInterface 
                ? $value->copy()->setTimezone('UTC')->format('Y-m-d H:i:s') 
                : ($value ? \Illuminate\Support\Carbon::parse($value, 'UTC')->format('Y-m-d H:i:s') : null),
        );
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function correction(): HasOne
    {
        return $this->hasOne(AttendanceCorrection::class);
    }

    public function scopeToday($query)
    {
        $startUtc = today()->copy()->startOfDay()->setTimezone('UTC');
        $endUtc = today()->copy()->endOfDay()->setTimezone('UTC');
        return $query->whereBetween('captured_at', [$startUtc, $endUtc]);
    }

    public function scopeByDevice($query, int $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    public function scopeByStudent($query, string $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeInDirection($query)
    {
        return $query->where('direction', 'in');
    }

    public function scopeOutDirection($query)
    {
        return $query->where('direction', 'out');
    }
}


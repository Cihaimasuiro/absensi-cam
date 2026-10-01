<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Device\Models\Device;
use App\Domain\Member\Models\Member;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttendanceLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', // UUID from edge — must be explicitly allowed for idempotent insert
        'member_id',
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
        'captured_at'  => 'datetime',
        'received_at'  => 'datetime',
        'score'        => 'float',
        'liveness_score' => 'float',
        'is_corrected' => 'boolean',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
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
        return $query->whereDate('captured_at', today());
    }

    public function scopeByDevice($query, int $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    public function scopeByMember($query, string $memberId)
    {
        return $query->where('member_id', $memberId);
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

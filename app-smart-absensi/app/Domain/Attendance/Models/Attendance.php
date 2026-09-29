<?php

namespace App\Domain\Attendance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $fillable = [
        'member_id',
        'name',
        'department',
        'device_id',
        'attended_at',
        'confidence',
        'status',
    ];

    protected $casts = [
        'attended_at' => 'datetime',
        'confidence'  => 'float',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Member\Models\Member::class);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('attended_at', today());
    }

    public function scopeByDevice($query, string $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }
}

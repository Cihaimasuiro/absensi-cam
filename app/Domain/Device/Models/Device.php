<?php

namespace App\Domain\Device\Models;

use App\Domain\School\Models\Building;
use App\Domain\School\Models\Classroom;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Device extends Authenticatable
{
    use HasFactory;
    use HasApiTokens;

    protected $fillable = [
        'building_id',
        'name',
        'device_code',
        'token_hash',
        'ip_address',
        'fw_version',
        'model_version',
        'last_heartbeat_at',
        'last_cpu_temp',
        'last_outbox_len',
        'status',
    ];

    protected $casts = [
        'last_heartbeat_at' => 'datetime',
        'last_cpu_temp'     => 'float',
        'last_outbox_len'   => 'integer',
    ];

    protected $hidden = ['token_hash'];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** Classrooms whose students this device is allowed to recognize (FR-E06) */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'device_groups');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DeviceLog::class);
    }

    public function scopeOnline($query)
    {
        return $query->where('status', 'online');
    }

    /** Mark online/offline based on heartbeat age (> 3 min = offline) */
    public function isOnline(): bool
    {
        if ($this->last_heartbeat_at === null) { return false; } return $this->last_heartbeat_at->diffInMinutes(now()) <= 3;
    }
}



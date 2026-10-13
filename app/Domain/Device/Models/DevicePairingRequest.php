<?php

namespace App\Domain\Device\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevicePairingRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'pairing_code',
        'poll_token_hash',
        'hostname',
        'mac_address',
        'ip_address',
        'model_version',
        'status',
        'device_id',
        'encrypted_payload',
        'expires_at',
        'approved_at',
        'claimed_at',
        'approved_by',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'approved_at' => 'datetime',
        'claimed_at'  => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}

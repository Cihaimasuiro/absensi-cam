<?php

namespace App\Domain\Device\Models;

use App\Domain\School\Models\Building;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PairingCode extends Model
{
    protected $fillable = [
        'building_id',
        'code_hash',
        'expires_at',
        'used_at',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    protected $hidden = ['code_hash'];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}

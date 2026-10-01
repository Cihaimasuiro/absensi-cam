<?php

namespace App\Domain\Member\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consent extends Model
{
    protected $fillable = [
        'member_id',
        'given_at',
        'withdrawn_at',
        'text_version',
        'recorded_by',
        'guardian_name',
        'guardian_relation',
        'notes',
    ];

    protected $casts = [
        'given_at'     => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isActive(): bool
    {
        return $this->withdrawn_at === null;
    }
}

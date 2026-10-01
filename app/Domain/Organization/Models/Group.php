<?php

namespace App\Domain\Organization\Models;

use App\Domain\Member\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'type',
        'is_active',
        'class_start_time',
        'late_threshold_enabled',
        'late_threshold_minutes',
        'track_checkout',
        'biometric_consent_certified',
    ];

    protected $casts = [
        'is_active'                    => 'boolean',
        'late_threshold_enabled'       => 'boolean',
        'track_checkout'               => 'boolean',
        'biometric_consent_certified'  => 'boolean',
        'late_threshold_minutes'       => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

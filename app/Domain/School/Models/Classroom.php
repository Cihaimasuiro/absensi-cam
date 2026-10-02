<?php

namespace App\Domain\School\Models;

use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classroom extends Model
{
    protected $fillable = [
        'school_id',
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

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

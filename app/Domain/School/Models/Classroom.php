<?php

namespace App\Domain\School\Models;

use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classroom extends Model
{
    use HasFactory;
    protected $fillable = [
        'school_id',
        'building_id',
        'name',
        'code',
        'type',
        'is_active',
        'start_time',
        'late_tolerance_minutes',
        'track_checkout',
        'biometric_consent_certified',
    ];

    protected $casts = [
        'is_active'                    => 'boolean',
        'track_checkout'               => 'boolean',
        'biometric_consent_certified'  => 'boolean',
        'late_tolerance_minutes'       => 'integer',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    protected static function booted()
    {
        static::updated(function (Classroom $classroom) {
            if ($classroom->wasChanged('building_id')) {
                // If a classroom moves to another building, we must bump the version_cursor 
                // of all its enrolled students so that devices pull the sync (either as upsert or delete).
                // We retrieve and touch them individually to trigger the saving event which handles the cursor.
                $classroom->students()->whereHas('faceTemplate')->with('faceTemplate')->get()->each(function ($student) {
                    $student->faceTemplate->touch();
                });
            }
        });
    }
}


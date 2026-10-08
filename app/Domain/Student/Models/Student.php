<?php

namespace App\Domain\Student\Models;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\School\Models\Classroom;
use App\Domain\School\Models\School;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory;
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'school_id',
        'classroom_id',
        'code',
        'name',
        'email',
        'phone',
        'role',
        'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    protected $appends = ['face_photo_url'];

    public function getFacePhotoUrlAttribute(): ?string
    {
        if ($this->relationLoaded('faceTemplate') && $this->faceTemplate && $this->faceTemplate->photo_path) {
            return route('students.photo', $this->id);
        }
        return null;
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'classroom_id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function activeConsent(): HasOne
    {
        return $this->hasOne(Consent::class)->whereNull('withdrawn_at')->latestOfMany('given_at');
    }

    public function faceTemplate(): HasOne
    {
        return $this->hasOne(FaceTemplate::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function hasActiveConsent(): bool
    {
        return $this->activeConsent()->exists();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEnrolled($query)
    {
        return $query->whereHas('faceTemplate');
    }

    protected static function booted()
    {
        static::deleting(function (Student $student) {
            // Cascade soft delete to faceTemplate so tombstone is sent to edge
            if ($student->faceTemplate) {
                $student->faceTemplate->delete();
            }
        });

        static::updated(function (Student $student) {
            if ($student->wasChanged('classroom_id') && $student->faceTemplate) {
                // Touch the faceTemplate to bump version_cursor so devices get the tombstone if it moved out of scope
                $student->faceTemplate->touch();
            }
        });
    }
}


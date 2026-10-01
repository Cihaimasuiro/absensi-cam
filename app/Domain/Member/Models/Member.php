<?php

namespace App\Domain\Member\Models;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Organization\Models\Group;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'group_id',
        'code',
        'name',
        'email',
        'phone',
        'role',
        'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
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
}

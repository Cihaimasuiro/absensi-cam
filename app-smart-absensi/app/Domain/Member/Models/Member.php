<?php

namespace App\Domain\Member\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    protected $fillable = [
        'employee_number',
        'name',
        'department',
        'branch',
        'organization',
        'is_active',
        'face_embedding',
        'embedding_updated_at',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'face_embedding'       => 'array',
        'embedding_updated_at' => 'datetime',
    ];

    protected $hidden = ['face_embedding']; // Jangan expose 512-dim vector ke API umum

    public function attendances(): HasMany
    {
        return $this->hasMany(\App\Domain\Attendance\Models\Attendance::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

<?php

namespace App\Domain\Organization\Models;

use App\Domain\Member\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = ['name', 'code', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

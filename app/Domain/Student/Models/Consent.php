<?php

namespace App\Domain\Student\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consent extends Model
{
    use HasFactory;
    protected $fillable = [
        'student_id',
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

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
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


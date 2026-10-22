<?php

namespace App\Domain\Enrollment\Models;

use App\Domain\Device\Models\Device;
use App\Domain\Student\Models\Student;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricQuarantine extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'device_id',
        'reason',
        'error_details',
        'status',
        'quarantined_at',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
    ];

    protected $casts = [
        'quarantined_at' => 'datetime',
        'resolved_at'    => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isQuarantined(): bool
    {
        return $this->status === 'quarantined';
    }
}

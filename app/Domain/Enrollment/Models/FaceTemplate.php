<?php

namespace App\Domain\Enrollment\Models;

use App\Domain\Member\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FaceTemplate extends Model
{
    use SoftDeletes; // soft-delete = tombstone; op=delete sent to edge via delta sync

    protected $fillable = [
        'member_id',
        'embedding_enc',
        'model_version',
        'version_cursor',
        'embedding_hash',
    ];

    protected $hidden = ['embedding_enc']; // Never expose ciphertext in API responses

    protected $casts = ['version_cursor' => 'integer'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** Whether this template's model version matches the given device model version */
    public function isCompatibleWith(string $deviceModelVersion): bool
    {
        return $this->model_version === $deviceModelVersion;
    }
}

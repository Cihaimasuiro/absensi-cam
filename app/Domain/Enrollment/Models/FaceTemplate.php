<?php

namespace App\Domain\Enrollment\Models;

use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FaceTemplate extends Model
{
    use HasFactory;
    use SoftDeletes; // soft-delete = tombstone; op=delete sent to edge via delta sync

    protected $fillable = [
        'student_id',
        'embedding_enc',
        'model_version',
        'version_cursor',
        'embedding_hash',
        'photo_path',
    ];

    protected $hidden = ['embedding_enc']; // Never expose ciphertext in API responses

    protected $casts = ['version_cursor' => 'integer'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** Whether this template's model version matches the given device model version */
    public function isCompatibleWith(string $deviceModelVersion): bool
    {
        return $this->model_version === $deviceModelVersion;
    }

    protected static function booted()
    {
        $assignCursor = function ($template) {
            \Illuminate\Support\Facades\Cache::lock('face_templates_cursor_lock', 10)->block(5, function () use ($template) {
                // If it's already set in this exact request/job, don't overwrite unless we want to force it
                // Actually, we ALWAYS want a new cursor on every save/delete so the edge pulls it.
                $next = \Illuminate\Support\Facades\DB::table('face_templates')->max('version_cursor') + 1;
                $template->version_cursor = $next;
            });
        };

        static::saving($assignCursor);
        static::deleting(function ($template) use ($assignCursor) {
            $assignCursor($template);
            // Must save quietly so we don't trigger saving again, but we just want to update the DB before it's deleted (soft delete)
            \Illuminate\Support\Facades\DB::table('face_templates')
                ->where('id', $template->id)
                ->update(['version_cursor' => $template->version_cursor]);
        });
    }
}


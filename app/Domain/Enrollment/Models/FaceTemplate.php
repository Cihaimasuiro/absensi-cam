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
    use SoftDeletes; // soft-delete = tombstone; op=delete dikirim ke edge via delta sync

    protected $fillable = [
        'student_id',
        'embedding_enc',
        'model_version',
        'version_cursor',
        'embedding_hash',
        'photo_path',
    ];

    protected $hidden = ['embedding_enc']; // Jangan pernah mengekspos ciphertext di response API

    protected $casts = ['version_cursor' => 'integer'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** Apakah model version template ini cocok dengan model version perangkat yang diberikan */
    public function isCompatibleWith(string $deviceModelVersion): bool
    {
        return $this->model_version === $deviceModelVersion;
    }

    protected static function booted()
    {
        $assignCursor = function ($template) {
            \Illuminate\Support\Facades\Cache::lock('face_templates_cursor_lock', 10)->block(5, function () use ($template) {
                // Jika sudah di-set pada request/job yang sama persis, jangan timpa kecuali memang ingin dipaksa
                // Sebenarnya, kita SELALU ingin cursor baru pada setiap save/delete agar edge menariknya.
                $next = \Illuminate\Support\Facades\DB::table('face_templates')->max('version_cursor') + 1;
                $template->version_cursor = $next;
            });
        };

        static::saving($assignCursor);
        static::deleting(function ($template) use ($assignCursor) {
            $assignCursor($template);
            // Harus disimpan secara senyap agar tidak memicu saving lagi, kita hanya ingin memperbarui DB sebelum datanya dihapus (soft delete)
            \Illuminate\Support\Facades\DB::table('face_templates')
                ->where('id', $template->id)
                ->update(['version_cursor' => $template->version_cursor]);
        });

        static::saved(function () {
            \Illuminate\Support\Facades\Cache::forever('templates_hash_epoch', ((int) \Illuminate\Support\Facades\Cache::get('templates_hash_epoch', 1)) + 1);
        });

        static::deleted(function () {
            \Illuminate\Support\Facades\Cache::forever('templates_hash_epoch', ((int) \Illuminate\Support\Facades\Cache::get('templates_hash_epoch', 1)) + 1);
        });
    }
}


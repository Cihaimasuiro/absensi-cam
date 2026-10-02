<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Face templates — enrollment result, distributed to edge via delta sync (FR-S03, FR-S07)
        // No photo stored here. Only embedding. Photo deleted immediately after face-embed job runs.
        Schema::create('face_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('student_id');
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            // Encrypted embedding: AES-256-GCM (12 byte nonce + N byte payload + 16 byte tag)
            // SFace 128-d float32 = 512 bytes → encrypted = 540 bytes, stored as base64 string
            $table->text('embedding_enc')->comment('AES-256-GCM ciphertext base64; nonce prepended');
            $table->string('model_version', 60)->comment('mis. sface-2021dec/1 — versi harus cocok dengan edge');
            // version_cursor untuk delta sync: edge meminta GET /templates?cursor=<N>
            $table->unsignedBigInteger('version_cursor')->default(0)->index()
                ->comment('Monotonically increasing; digunakan edge untuk delta pull');
            $table->string('embedding_hash', 64)->nullable()
                ->comment('SHA-256 plaintext embedding untuk deteksi perubahan (AC-44)');
            $table->timestamps();
            $table->softDeletes()->comment('Soft-delete = tombstone; op=delete dikirim ke edge');

            $table->unique('student_id')->comment('Satu template aktif per anggota');
            $table->index(['model_version', 'version_cursor']);
            $table->index(['deleted_at', 'version_cursor'])
                ->comment('Query tombstone yang belum di-sync');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_templates');
    }
};

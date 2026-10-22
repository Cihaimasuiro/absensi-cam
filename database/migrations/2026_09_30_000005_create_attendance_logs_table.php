<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Attendance logs — disinkronkan dari outbox SQLite edge (FR-S06, §11.1)
        // Primary key = UUID dari edge (UUIDv7); insert idempoten per PRD §11.2
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('UUID dari edge (UUIDv7); idempotensi kunci');
            $table->uuid('student_id')->nullable()->comment('Null jika anggota dihapus');
            $table->foreign('student_id')->references('id')->on('students')->nullOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->timestamp('captured_at')->comment('Waktu absen dari edge (UTC)');
            $table->enum('direction', ['in', 'out'])->default('in');
            $table->float('score')->comment('Cosine similarity SFace (0–1)');
            $table->float('liveness_score')->nullable()->comment('Mini-FASNet score (0–1)');
            $table->string('time_source', 10)->default('ntp')
                ->comment('ntp|rtc|unsynced (dari field time_source edge)');
            $table->timestamp('received_at')->useCurrent()->comment('Waktu diterima server');
            // Pelacakan koreksi manual — data asli tidak pernah diubah (FR-S10)
            $table->boolean('is_corrected')->default(false)->index();
            $table->timestamps();

            // Satu record per siswa per direction per hari per device — tetapi edge memakai UUID sehingga
            // kita mengandalkan keunikan UUID dari edge, bukan composite unique.
            $table->index(['student_id', 'captured_at']);
            $table->index(['device_id', 'captured_at']);
            $table->index('captured_at');
        });

        // Corrections — data asli immutable, baris baru mencatat delta (FR-S10, §11.1)
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->uuid('attendance_log_id');
            $table->foreign('attendance_log_id')->references('id')->on('attendance_logs')->cascadeOnDelete();
            $table->foreignId('corrected_by')->constrained('users');
            $table->text('reason');
            $table->timestamp('original_captured_at')->nullable();
            $table->timestamp('corrected_captured_at')->nullable();
            $table->string('original_direction', 5)->nullable();
            $table->string('corrected_direction', 5)->nullable();
            $table->timestamps();

            $table->index('attendance_log_id');
        });

        // Device logs — pengiriman log error/info edge lewat POST /logs (FR-E12, §11.1)
        Schema::create('device_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('level', 10)->default('info')->comment('debug|info|warn|error');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('logged_at')->comment('Waktu di edge');
            $table->timestamp('received_at')->useCurrent();

            // Retensi: 7 hari per PRD §12.3 — dibersihkan oleh scheduled command
            $table->index(['device_id', 'logged_at']);
            $table->index('logged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_logs');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_logs');
    }
};

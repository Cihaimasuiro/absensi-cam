<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Attendance logs — synced from edge SQLite outbox (FR-S06, §11.1)
        // Primary key = UUID from edge (UUIDv7); idempotent insert per PRD §11.2
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('UUID dari edge (UUIDv7); idempotensi kunci');
            $table->uuid('member_id')->nullable()->comment('Null jika anggota dihapus');
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->timestamp('captured_at')->comment('Waktu absen dari edge (UTC)');
            $table->enum('direction', ['in', 'out'])->default('in');
            $table->float('score')->comment('Cosine similarity SFace (0–1)');
            $table->float('liveness_score')->nullable()->comment('Mini-FASNet score (0–1)');
            $table->string('time_source', 10)->default('ntp')
                ->comment('ntp|rtc|unsynced (dari field time_source edge)');
            $table->timestamp('received_at')->useCurrent()->comment('Waktu diterima server');
            // Manual correction tracking — original is never mutated (FR-S10)
            $table->boolean('is_corrected')->default(false)->index();
            $table->timestamps();

            // One record per member per direction per day per device — but edge uses UUID so
            // we rely on UUID uniqueness from edge, not a composite unique.
            $table->index(['member_id', 'captured_at']);
            $table->index(['device_id', 'captured_at']);
            $table->index('captured_at');
        });

        // Corrections — immutable original, new row records delta (FR-S10, §11.1)
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

        // Device logs — edge error/info log shipping via POST /logs (FR-E12, §11.1)
        Schema::create('device_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('level', 10)->default('info')->comment('debug|info|warn|error');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('logged_at')->comment('Waktu di edge');
            $table->timestamp('received_at')->useCurrent();

            // Retention: 7 days per PRD §12.3 — cleaned by scheduled command
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Buildings = physical locations / buildings / doors (FR-S02)
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('name', 150);
            $table->string('code', 50)->unique()->comment('Kode cabang misal: JKT-01');
            $table->string('address')->nullable();
            $table->string('timezone', 50)->default('Asia/Jakarta')
                ->comment('WIB=Asia/Jakarta, WITA=Asia/Makassar, WIT=Asia/Jayapura');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // Edge devices (Orange Pi Lite 2 terminals) — FR-S05, FR-S08
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->nullable()->constrained('buildings')->nullOnDelete();
            $table->string('name', 100);
            $table->string('device_code', 50)->unique()->comment('Kode unik dari edge, mis. dev-0007');
            $table->string('token_hash', 64)->nullable()->comment('SHA-256 hash Sanctum token; null = belum dipasangkan');
            $table->string('ip_address', 45)->nullable();
            $table->string('fw_version', 30)->nullable()->comment('Versi firmware edge-engine');
            $table->string('model_version', 60)->nullable()->comment('mis. arcface-512/1');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->float('last_cpu_temp')->nullable()->comment('°C dari heartbeat terakhir');
            $table->unsignedSmallInteger('last_outbox_len')->nullable()->comment('Panjang outbox terakhir');
            $table->enum('status', ['online', 'offline', 'maintenance'])->default('offline')->index();
            $table->timestamps();
        });

        // M:N — devices can recognize students from multiple classrooms (FR-E06, FR-S07)
        Schema::create('device_groups', function (Blueprint $table) {
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->primary(['device_id', 'classroom_id']);
        });

        // One-time pairing codes — FR-S05
        Schema::create('pairing_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained('buildings')->cascadeOnDelete();
            $table->string('code_hash', 64)->unique()->comment('SHA-256 hash kode pairing 8-karakter');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pairing_codes');
        Schema::dropIfExists('device_groups');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('buildings');
    }
};

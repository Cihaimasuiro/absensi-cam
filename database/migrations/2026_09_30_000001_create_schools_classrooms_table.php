<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 50)->unique()->nullable()->comment('Kode singkat org');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // Division / department / class inside an org (FR-S02)
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('code', 50)->nullable()->comment('Kode divisi/kelas');
            $table->string('type', 30)->default('department')->comment('department|class|division');
            $table->boolean('is_active')->default(true)->index();
            // Attendance rules per group (from Facenox AttendanceGroup concept)
            $table->time('class_start_time')->default('08:00')->comment('Jam masuk resmi');
            $table->boolean('late_threshold_enabled')->default(false);
            $table->unsignedSmallInteger('late_threshold_minutes')->default(15);
            $table->boolean('track_checkout')->default(false)->comment('Rekam jam keluar');
            $table->boolean('biometric_consent_certified')->default(false)
                ->comment('Semua anggota sudah berikan consent');
            $table->timestamps();

            $table->unique(['school_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classrooms');
        Schema::dropIfExists('schools');
    }
};

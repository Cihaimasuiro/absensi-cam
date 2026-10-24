<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambahkan softDeletes pada schools untuk basis data yang telah dimigrasi
        if (!Schema::hasColumn('schools', 'deleted_at')) {
            Schema::table('schools', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        // 2. Modifikasi foreign key attendance_logs.device_id menjadi nullOnDelete
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            // SQLite merekonstruksi skema tabel jika kolom diubah
            Schema::table('attendance_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('device_id')->nullable()->change();
            });
        } else {
            Schema::table('attendance_logs', function (Blueprint $table) {
                $table->dropForeign(['device_id']);
                $table->unsignedBigInteger('device_id')->nullable()->change();
                $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            });
        }

        // 3. Normalisasi late_tolerance_minutes = null untuk staff pada basis data lama
        DB::table('classrooms')
            ->where('type', 'staff')
            ->where('late_tolerance_minutes', 0)
            ->update(['late_tolerance_minutes' => null]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('schools', 'deleted_at')) {
            Schema::table('schools', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        $driver = DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            Schema::table('attendance_logs', function (Blueprint $table) {
                $table->dropForeign(['device_id']);
                $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            });
        }
    }
};

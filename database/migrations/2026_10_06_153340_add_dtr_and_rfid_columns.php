<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->time('start_time')->default('07:00:00')->after('building_id');
            $table->integer('late_tolerance_minutes')->default(15)->after('start_time');
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->string('method')->default('face')->after('time_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn('method');
        });

        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'late_tolerance_minutes']);
        });
    }
};

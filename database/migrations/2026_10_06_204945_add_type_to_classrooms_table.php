<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // We already have 'type' as string(30). We will use 'class' and 'staff'.
        // Migrate existing data based on group name matching GURU or STAF
        DB::table('classrooms')
            ->where('name', 'like', '%GURU%')
            ->orWhere('name', 'like', '%STAF%')
            ->update(['type' => 'staff']);

        // Drop the old duplicated concept columns to avoid confusion
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropColumn(['class_start_time', 'late_threshold_enabled', 'late_threshold_minutes']);
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->time('class_start_time')->default('08:00')->comment('Jam masuk resmi');
            $table->boolean('late_threshold_enabled')->default(false);
            $table->unsignedSmallInteger('late_threshold_minutes')->default(15);
        });
    }
};

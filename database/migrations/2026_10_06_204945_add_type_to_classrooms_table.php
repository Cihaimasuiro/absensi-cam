<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Copy old timing data to the new columns
        DB::table('classrooms')->orderBy('id')->chunk(100, function ($classrooms) {
            foreach ($classrooms as $classroom) {
                // Determine new type. 'department' and 'division' -> 'staff', others keep or fallback to 'class'.
                // If the name has GURU or STAF, force it to 'staff'.
                $newType = 'class';
                if (str_contains(strtoupper($classroom->name), 'GURU') || str_contains(strtoupper($classroom->name), 'STAF')) {
                    $newType = 'staff';
                } elseif (in_array($classroom->type, ['department', 'division'])) {
                    $newType = 'staff';
                }

                // late_threshold_enabled=false means they didn't care about late. We map this to the max tolerance (120 mins).
                $tolerance = $classroom->late_threshold_enabled ? $classroom->late_threshold_minutes : 120;

                DB::table('classrooms')->where('id', $classroom->id)->update([
                    'type' => $newType,
                    'start_time' => $classroom->class_start_time,
                    'late_tolerance_minutes' => $tolerance,
                ]);
            }
        });

        // Drop the old duplicated concept columns
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

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
        $totalConverted = 0;
        $staffNames = [];
        
        DB::table('classrooms')->orderBy('id')->chunk(100, function ($classrooms) use (&$totalConverted, &$staffNames) {
            foreach ($classrooms as $classroom) {
                // Determine new type. Only GURU or STAF becomes staff, everything else is a class.
                $newType = 'class';
                if (str_contains(strtoupper($classroom->name), 'GURU') || str_contains(strtoupper($classroom->name), 'STAF')) {
                    $newType = 'staff';
                    $staffNames[] = $classroom->name;
                }

                // late_threshold_enabled=false means they didn't care about late. We map this to 0.
                $tolerance = $classroom->late_threshold_enabled ? $classroom->late_threshold_minutes : 0;

                DB::table('classrooms')->where('id', $classroom->id)->update([
                    'type' => $newType,
                    'start_time' => $classroom->class_start_time,
                    'late_tolerance_minutes' => $tolerance,
                ]);
                $totalConverted++;
            }
        });

        // Log the conversion count
        if ($totalConverted > 0) {
            \Illuminate\Support\Facades\Log::info("Migrated {$totalConverted} classrooms to new timing schema.", [
                'staff_rows' => $staffNames
            ]);
        }

        // Drop the old duplicated concept columns
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropColumn(['class_start_time', 'late_threshold_enabled', 'late_threshold_minutes']);
        });
    }

    public function down(): void
    {
        // Recreate the old columns (nullable temporarily)
        // Data is copied back as best effort (since tolerance !== null determines if it was enabled).
        Schema::table('classrooms', function (Blueprint $table) {
            $table->time('class_start_time')->default('08:00')->comment('Jam masuk resmi');
            $table->boolean('late_threshold_enabled')->default(false);
            $table->unsignedSmallInteger('late_threshold_minutes')->default(15);
        });
        
        // Copy data back best-effort
        DB::table('classrooms')->orderBy('id')->chunk(100, function ($classrooms) {
            foreach ($classrooms as $classroom) {
                $enabled = $classroom->late_tolerance_minutes !== null;
                DB::table('classrooms')->where('id', $classroom->id)->update([
                    'class_start_time' => $classroom->start_time,
                    'late_threshold_minutes' => $classroom->late_tolerance_minutes,
                    'late_threshold_enabled' => $enabled,
                ]);
            }
        });
    }
};

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_maps_old_columns_and_types_correctly()
    {
        // Actually, we can't easily test migration from PHP code if we use RefreshDatabase 
        // because it runs up all migrations before the test method!
        // To test migration, we'd have to roll back that specific migration, insert data, and migrate again.
        
        // Setup: rollback the target migration
        $this->artisan('migrate:rollback', ['--step' => 1]); // rolls back add_type_to_classrooms_table
        
        // Insert old data
        $schoolId = DB::table('schools')->insertGetId([
            'name' => 'Test School',
            'code' => 'TEST',
            'is_active' => true,
        ]);
        
        $class1Id = DB::table('classrooms')->insertGetId([
            'school_id' => $schoolId,
            'name' => 'Kelas 10',
            'type' => 'department', // old default
            'class_start_time' => '07:30:00',
            'late_threshold_enabled' => true,
            'late_threshold_minutes' => 10,
        ]);
        
        $staff1Id = DB::table('classrooms')->insertGetId([
            'school_id' => $schoolId,
            'name' => 'STAF DAN GURU',
            'type' => 'division',
            'class_start_time' => '06:30:00',
            'late_threshold_enabled' => false,
            'late_threshold_minutes' => 15,
        ]);

        // Run the migration
        $this->artisan('migrate');

        // Assert
        $class1 = DB::table('classrooms')->where('id', $class1Id)->first();
        $this->assertEquals('class', $class1->type);
        $this->assertEquals('07:30:00', $class1->start_time);
        $this->assertEquals(10, $class1->late_tolerance_minutes);

        $staff1 = DB::table('classrooms')->where('id', $staff1Id)->first();
        $this->assertEquals('staff', $staff1->type);
        $this->assertEquals('06:30:00', $staff1->start_time);
        $this->assertEquals(120, $staff1->late_tolerance_minutes); // false -> 120
    }
}

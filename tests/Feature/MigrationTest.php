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
        // Sebenarnya, kita tidak bisa dengan mudah menguji migration dari kode PHP jika memakai RefreshDatabase
        // karena trait itu menjalankan semua migration sebelum method test dijalankan!
        // Untuk menguji migration, kita harus rollback migration tertentu, insert data, lalu migrate lagi.
        
        // Setup: rollback migration target
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_10_06_204945_add_type_to_classrooms_table.php'
        ]);
        
        // Insert data lama
        $schoolId = DB::table('schools')->insertGetId([
            'name' => 'Test School',
            'code' => 'TEST',
            'is_active' => true,
        ]);
        
        $class1Id = DB::table('classrooms')->insertGetId([
            'school_id' => $schoolId,
            'name' => 'Kelas 10',
            'type' => 'department', // default lama
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

        // Jalankan migration
        $this->artisan('migrate');

        // Assertion
        $class1 = DB::table('classrooms')->where('id', $class1Id)->first();
        $this->assertEquals('class', $class1->type);
        $this->assertEquals('07:30:00', $class1->start_time);
        $this->assertEquals(10, $class1->late_tolerance_minutes);

        $staff1 = DB::table('classrooms')->where('id', $staff1Id)->first();
        $this->assertEquals('staff', $staff1->type);
        $this->assertEquals('06:30:00', $staff1->start_time);
        $this->assertNull($staff1->late_tolerance_minutes); // false -> null
    }
}

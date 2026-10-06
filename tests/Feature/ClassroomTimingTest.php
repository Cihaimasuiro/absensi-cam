<?php

namespace Tests\Feature;

use App\Domain\School\Models\Classroom;
use App\Domain\School\Models\School;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassroomTimingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        
        $this->school = School::factory()->create();
    }

    public function test_can_create_classroom_with_timing_and_type()
    {
        $response = $this->actingAs($this->admin)->post(route('schools.classrooms.store'), [
            'school_id' => $this->school->id,
            'name' => '10A',
            'type' => 'class',
            'start_time' => '07:30',
            'late_tolerance_minutes' => 10,
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('classrooms', [
            'name' => '10A',
            'type' => 'class',
            'start_time' => '07:30',
            'late_tolerance_minutes' => 10,
        ]);
    }

    public function test_can_update_individual_classroom_timing()
    {
        $classroom = Classroom::create([
            'school_id' => $this->school->id,
            'name' => '10B',
            'type' => 'class',
            'start_time' => '07:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $response = $this->actingAs($this->admin)->put(route('schools.classrooms.update', $classroom), [
            'name' => $classroom->name,
            'type' => 'staff',
            'start_time' => '08:00',
            'late_tolerance_minutes' => 30,
        ]);

        $response->assertSessionHas('success');
        
        $this->assertDatabaseHas('classrooms', [
            'id' => $classroom->id,
            'type' => 'staff',
            'start_time' => '08:00',
            'late_tolerance_minutes' => 30,
        ]);

        // Check activity log
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Classroom::class,
            'subject_id' => $classroom->id,
            'description' => 'Updated classroom timing/type',
        ]);
    }

    public function test_can_bulk_update_classroom_timings()
    {
        $class1 = Classroom::create(['name' => 'C1', 'school_id' => $this->school->id, 'start_time' => '07:00:00']);
        $class2 = Classroom::create(['name' => 'C2', 'school_id' => $this->school->id, 'start_time' => '07:15:00']);

        $response = $this->actingAs($this->admin)->put(route('schools.classrooms.bulkUpdateTiming', $this->school), [
            'start_time' => '06:45',
            'late_tolerance_minutes' => 5,
            'confirm_retroactive' => '1',
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('classrooms', [
            'id' => $class1->id,
            'start_time' => '06:45',
            'late_tolerance_minutes' => 5,
        ]);

        $this->assertDatabaseHas('classrooms', [
            'id' => $class2->id,
            'start_time' => '06:45',
            'late_tolerance_minutes' => 5,
        ]);

        $this->assertDatabaseCount('activity_log', 2);
    }
}

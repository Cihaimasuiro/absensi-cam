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

        // Check activity log properties
        $log = \Spatie\Activitylog\Models\Activity::where('subject_type', Classroom::class)
            ->where('subject_id', $classroom->id)
            ->first();
            
        $this->assertNotNull($log);
        $this->assertEquals('07:00:00', $log->properties['old']['start_time']);
        $this->assertEquals('08:00', $log->properties['new']['start_time']);
        $this->assertEquals('class', $log->properties['old']['type']);
        $this->assertEquals('staff', $log->properties['new']['type']);
    }

    public function test_can_bulk_update_classroom_timings_with_type_filter()
    {
        $class1 = Classroom::create(['name' => 'C1', 'school_id' => $this->school->id, 'start_time' => '07:00:00', 'type' => 'class']);
        $staff1 = Classroom::create(['name' => 'S1', 'school_id' => $this->school->id, 'start_time' => '06:00:00', 'type' => 'staff']);

        $response = $this->actingAs($this->admin)->put(route('schools.classrooms.bulkUpdateTiming', $this->school), [
            'start_time' => '06:45',
            'late_tolerance_minutes' => 5,
            'type_filter' => 'class',
            'confirm_retroactive' => '1',
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('classrooms', [
            'id' => $class1->id,
            'start_time' => '06:45',
            'late_tolerance_minutes' => 5,
        ]);

        // Staff should remain untouched
        $this->assertDatabaseHas('classrooms', [
            'id' => $staff1->id,
            'start_time' => '06:00:00',
        ]);

        $log = \Spatie\Activitylog\Models\Activity::where('subject_id', $class1->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals('07:00:00', $log->properties['old']['start_time']);
        $this->assertEquals('06:45', $log->properties['new']['start_time']);
    }

    public function test_bulk_update_rejects_missing_confirmation_and_invalid_tolerance()
    {
        $response = $this->actingAs($this->admin)->put(route('schools.classrooms.bulkUpdateTiming', $this->school), [
            'start_time' => '06:45',
            'late_tolerance_minutes' => 121, // invalid max
            'type_filter' => 'all',
            // missing confirm_retroactive
        ]);

        $response->assertSessionHasErrors(['confirm_retroactive', 'late_tolerance_minutes']);
    }

    public function test_non_admin_gets_403()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)->post(route('schools.classrooms.store'), [
            'school_id' => $this->school->id,
            'name' => '10A',
            'type' => 'class',
            'start_time' => '07:30',
            'late_tolerance_minutes' => 10,
        ]);

        $response->assertStatus(403);
    }
}

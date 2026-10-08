<?php

namespace Tests\Feature;

use App\Domain\User\Models\User;
use App\Domain\School\Models\School;
use App\Domain\School\Models\Classroom;
use App\Domain\Student\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_student_with_classroom_and_role()
    {
        $role = Role::create(['name' => 'super_admin']);
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $school = School::create(['name' => 'SMK Negeri 1', 'is_active' => true]);
        $classroom = Classroom::create([
            'school_id' => $school->id,
            'name' => 'XII RPL 1',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post('/students', [
            'code' => 'STUDENT-101',
            'name' => 'Ahmad Fauzi',
            'school_id' => $school->id,
            'classroom_id' => $classroom->id,
            'role' => 'student',
            'email' => 'ahmad@example.com',
            'phone' => '081234567890',
            'has_consent' => '1',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/students');

        $this->assertDatabaseHas('students', [
            'code' => 'STUDENT-101',
            'name' => 'Ahmad Fauzi',
            'school_id' => $school->id,
            'classroom_id' => $classroom->id,
            'role' => 'student',
            'email' => 'ahmad@example.com',
            'phone' => '081234567890',
            'is_active' => true,
        ]);

        // Verify it renders on the index page with classroom name
        $indexResponse = $this->actingAs($user)->get('/students');
        $indexResponse->assertStatus(200);
        $indexResponse->assertSee('XII RPL 1');
        $indexResponse->assertSee('SMK Negeri 1');
    }

    public function test_validation_fails_on_duplicate_code()
    {
        $role = Role::create(['name' => 'super_admin']);
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        Student::create([
            'code' => 'DUP-001',
            'name' => 'Existing Student',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->from('/students')->post('/students', [
            'code' => 'DUP-001',
            'name' => 'Duplicate Student',
            'has_consent' => '1',
        ]);

        $response->assertSessionHasErrors(['code']);
        $response->assertRedirect('/students');
    }

    public function test_can_update_student_classroom_and_role()
    {
        $role = Role::create(['name' => 'super_admin']);
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $school = School::create(['name' => 'SMK Negeri 1', 'is_active' => true]);
        $classroom = Classroom::create([
            'school_id' => $school->id,
            'name' => 'XII TKJ 2',
            'is_active' => true,
        ]);

        $student = Student::create([
            'code' => 'UPDATE-001',
            'name' => 'Budi Santoso',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->put("/students/{$student->id}", [
            'code' => 'UPDATE-001',
            'name' => 'Budi Santoso Updated',
            'school_id' => $school->id,
            'classroom_id' => $classroom->id,
            'role' => 'teacher',
            'is_active' => '1',
            'has_consent' => '1',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/students');

        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'name' => 'Budi Santoso Updated',
            'classroom_id' => $classroom->id,
            'role' => 'teacher',
        ]);
    }
}

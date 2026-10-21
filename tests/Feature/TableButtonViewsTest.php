<?php

namespace Tests\Feature;

use App\Domain\Device\Models\Device;
use App\Domain\School\Models\School;
use App\Domain\Student\Models\Student;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TableButtonViewsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
    }

    public function test_schools_page_renders_with_proper_button_classes(): void
    {
        School::create(['name' => 'SMK Test', 'code' => 'SMK-1']);

        $response = $this->actingAs($this->admin)->get(route('schools.index'));

        $response->assertOk();
        $response->assertSee('btn btn-secondary btn-sm', false);
        $response->assertSee('btn btn-danger-outline btn-sm', false);
        $response->assertDontSee('class="btn-link"', false);
    }

    public function test_devices_page_renders_with_proper_button_classes(): void
    {
        Device::create([
            'device_code' => 'DEV-001',
            'name' => 'Cam 1',
            'api_token_hash' => hash('sha256', 'token123'),
        ]);

        $response = $this->actingAs($this->admin)->get(route('devices.index'));

        $response->assertOk();
        $response->assertSee('btn btn-secondary btn-sm', false);
        $response->assertSee('btn btn-danger-outline btn-sm', false);
        $response->assertDontSee('class="btn-link"', false);
    }

    public function test_students_page_renders_with_proper_button_classes(): void
    {
        $school = School::create(['name' => 'SMK Test', 'code' => 'SMK-1']);
        Student::create([
            'school_id' => $school->id,
            'name' => 'Budi Santoso',
            'code' => 'NIS001',
            'role' => 'student',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('students.index', ['view_all' => 1]));

        $response->assertOk();
        $response->assertSee('btn btn-secondary btn-sm', false);
        $response->assertSee('btn btn-danger-outline btn-sm', false);
        $response->assertDontSee('class="btn-danger-link"', false);
        $response->assertSee('id="btnBulkEnrollMember"', false);
        $response->assertSee('id="btnImportMember"', false);
        $response->assertSee('id="btnCreateMember"', false);
        $response->assertSee('id="createMemberModal"', false);
        $response->assertSee('id="importMemberModal"', false);
        $response->assertSee('id="bulkEnrollMemberModal"', false);
    }

    public function test_reports_page_renders_with_proper_button_classes(): void
    {
        $school = School::create(['name' => 'SMK Test', 'code' => 'SMK-1']);
        $student = Student::create([
            'school_id' => $school->id,
            'name' => 'Budi Santoso',
            'code' => 'NIS001',
            'role' => 'student',
            'is_active' => true,
        ]);
        $device = Device::create([
            'device_code' => 'DEV-001',
            'name' => 'Cam 1',
            'api_token_hash' => hash('sha256', 'token123'),
        ]);

        \App\Domain\Attendance\Models\AttendanceLog::create([
            'student_id' => $student->id,
            'device_id' => $device->id,
            'captured_at' => now(),
            'direction' => 'in',
            'score' => 0.95,
        ]);

        $response = $this->actingAs($this->admin)->get(route('reports.index'));

        $response->assertOk();
        $response->assertSee('btn btn-secondary btn-sm', false);
        $response->assertSee('btn-export-csv', false);
        $response->assertSee('btn-export-xlsx', false);
        $response->assertSee('btn-export-pdf', false);
        $response->assertSee('file-down', false);
        $response->assertSee('file-spreadsheet', false);
        $response->assertSee('file-text', false);
        $response->assertSee('Koreksi', false);
        $response->assertSee('Log', false);
    }
}

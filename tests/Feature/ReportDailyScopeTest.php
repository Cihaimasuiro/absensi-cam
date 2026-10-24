<?php

namespace Tests\Feature;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Device\Models\Device;
use App\Domain\School\Models\Building;
use App\Domain\School\Models\School;
use App\Domain\Student\Models\Student;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportDailyScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin']);
        Role::firstOrCreate(['name' => 'admin']);
    }

    public function test_first_log_id_subquery_is_strictly_scoped_to_the_specific_date(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        date_default_timezone_set('Asia/Jakarta');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $school = School::factory()->create();
        $building = Building::factory()->create(['school_id' => $school->id]);
        $device = Device::factory()->create(['building_id' => $building->id]);
        $student = Student::factory()->create(['school_id' => $school->id]);

        // Hari 1: 2026-10-01 (07:00 WIB = 00:00 UTC)
        $day1Log1 = AttendanceLog::create([
            'id'          => '11111111-1111-1111-1111-111111111111',
            'student_id'  => $student->id,
            'device_id'   => $device->id,
            'captured_at' => '2026-10-01 00:00:00',
            'direction'   => 'in',
            'score'       => 0.95,
            'time_source' => 'ntp',
        ]);

        $day1Log2 = AttendanceLog::create([
            'id'          => '11111111-1111-1111-1111-111111111112',
            'student_id'  => $student->id,
            'device_id'   => $device->id,
            'captured_at' => '2026-10-01 08:00:00',
            'direction'   => 'out',
            'score'       => 0.95,
            'time_source' => 'ntp',
        ]);

        // Hari 2: 2026-10-02 (07:30 WIB = 00:30 UTC)
        $day2Log1 = AttendanceLog::create([
            'id'          => '22222222-2222-2222-2222-222222222221',
            'student_id'  => $student->id,
            'device_id'   => $device->id,
            'captured_at' => '2026-10-02 00:30:00',
            'direction'   => 'in',
            'score'       => 0.95,
            'time_source' => 'ntp',
        ]);

        $day2Log2 = AttendanceLog::create([
            'id'          => '22222222-2222-2222-2222-222222222222',
            'student_id'  => $student->id,
            'device_id'   => $device->id,
            'captured_at' => '2026-10-02 09:00:00',
            'direction'   => 'out',
            'score'       => 0.95,
            'time_source' => 'ntp',
        ]);

        $this->actingAs($admin);

        $response = $this->get(route('reports.index', [
            'start' => '2026-10-01',
            'end'   => '2026-10-02',
        ]));

        $response->assertStatus(200);

        /** @var \Illuminate\Pagination\LengthAwarePaginator $logs */
        $logs = $response->viewData('logs');
        $this->assertCount(2, $logs);

        $items = collect($logs->items());
        $day1Record = $items->firstWhere('log_date', '2026-10-01');
        $day2Record = $items->firstWhere('log_date', '2026-10-02');

        $this->assertNotNull($day1Record);
        $this->assertNotNull($day2Record);

        // Hari 1 first_log_id haruslah log pertama hari 1
        $this->assertEquals($day1Log1->id, $day1Record->first_log_id);

        // Hari 2 first_log_id HARUSLAH log pertama hari 2, BUKAN log hari 1!
        $this->assertEquals($day2Log1->id, $day2Record->first_log_id);
    }
}

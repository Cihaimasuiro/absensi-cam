<?php

namespace Tests\Feature;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Student\Models\Student;
use App\Domain\School\Models\School;
use App\Domain\User\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        Role::firstOrCreate(['name' => 'super_admin']);
        Role::firstOrCreate(['name' => 'admin']);
    }

    public function test_admin_can_correct_attendance_and_store_utc()
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $school = School::factory()->create();
        $student = Student::factory()->create(['school_id' => $school->id]);
        $building = \App\Domain\School\Models\Building::factory()->create(['school_id' => $school->id]);
        $device = \App\Domain\Device\Models\Device::factory()->create(['building_id' => $building->id]);
        
        // Simulating an original log stored as UTC
        $originalUtc = '2026-10-01 23:30:00'; // 06:30 WIB next day
        $log = AttendanceLog::create([
            'student_id'     => $student->id,
            'device_id'      => $device->id,
            'captured_at'    => $originalUtc,
            'direction'      => 'in',
            'time_source'    => 'server',
            'score'          => 0.99,
            'liveness_score' => 0.95,
        ]);

        $this->actingAs($admin);

        // Correction payload (sent in local time, e.g., WIB)
        // Admin corrects it to 08:00 WIB the next day
        $correctedLocal = '2026-10-02 08:00:00'; 
        
        $response = $this->post(route('reports.logs.correct', $log->id), [
            'reason'                => 'Wrong shift',
            'corrected_captured_at' => $correctedLocal,
            'corrected_direction'   => 'out',
        ]);

        $response->assertRedirect();
        
        $log->refresh();
        $this->assertTrue($log->is_corrected);
        $this->assertEquals('out', $log->direction);

        // Accessor should return local time
        $this->assertEquals($correctedLocal, $log->captured_at->format('Y-m-d H:i:s'));
        
        // But the raw DB value should be UTC!
        $rawLog = \Illuminate\Support\Facades\DB::table('attendance_logs')->where('id', $log->id)->first();
        // 08:00 WIB = 01:00 UTC
        $this->assertEquals('2026-10-02 01:00:00', $rawLog->captured_at);

        // Check AttendanceCorrection record
        $correction = AttendanceCorrection::where('attendance_log_id', $log->id)->first();
        $this->assertNotNull($correction);
        $this->assertEquals('Wrong shift', $correction->reason);
        
        // Check raw DB values for Correction
        $rawCorrection = \Illuminate\Support\Facades\DB::table('attendance_corrections')->where('id', $correction->id)->first();
        $this->assertEquals($originalUtc, $rawCorrection->original_captured_at);
        $this->assertEquals('2026-10-02 01:00:00', $rawCorrection->corrected_captured_at);
        $this->assertEquals('in', $rawCorrection->original_direction);
        $this->assertEquals('out', $rawCorrection->corrected_direction);

        // A second correction should be blocked with 422
        $response2 = $this->post(route('reports.logs.correct', $log->id), [
            'reason'                => 'Double correction',
            'corrected_captured_at' => $correctedLocal,
            'corrected_direction'   => 'in',
        ]);
        $response2->assertStatus(422);
    }
}

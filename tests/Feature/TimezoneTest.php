<?php

namespace Tests\Feature;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Device\Models\Device;
use App\Domain\School\Models\School;
use App\Domain\Student\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_utc_captured_at_is_handled_correctly_for_local_timezone()
    {
        // Setup timezone to Jakarta for the test (just in case it's not)
        config(['app.timezone' => 'Asia/Jakarta']);
        date_default_timezone_set('Asia/Jakarta');

        $school = School::factory()->create();
        $student = Student::factory()->create(['school_id' => $school->id]);
        $building = \App\Domain\School\Models\Building::factory()->create(['school_id' => $school->id]);
        $device = Device::factory()->create(['building_id' => $building->id]);
        
        $token = $device->createToken('edge-engine', ['device'])->plainTextToken;

        // 2026-09-30 23:30:00 UTC is 2026-10-01 06:30:00 WIB (Jakarta)
        $utcTime = '2026-09-30T23:30:00Z';
        $uuid = Str::uuid()->toString();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/attendance/batch', [
            'records' => [
                [
                    'id' => $uuid,
                    'student_id' => $student->id,
                    'captured_at' => $utcTime,
                    'direction' => 'in',
                    'score' => 0.95,
                    'liveness_score' => 0.9,
                    'time_source' => 'ntp',
                ]
            ]
        ]);

        $response->assertStatus(200);

        // Verify the database has the record
        $this->assertDatabaseHas('attendance_logs', [
            'id' => $uuid,
            'student_id' => $student->id,
        ]);

        $log = AttendanceLog::find($uuid);

        // Accessor should format using the app timezone by default.
        // The time should represent 06:30:00 in Jakarta time.
        $this->assertEquals('2026-10-01 06:30:00', $log->captured_at->format('Y-m-d H:i:s'));
        
        // The raw DB value MUST be UTC.
        $rawLog = \Illuminate\Support\Facades\DB::table('attendance_logs')->where('id', $uuid)->first();
        $this->assertEquals('2026-09-30 23:30:00', $rawLog->captured_at);

        // Let's test the `scopeToday` method if we mock 'today' to be 2026-10-01
        Carbon::setTestNow('2026-10-01 10:00:00');
        
        $todayLogs = AttendanceLog::today()->get();
        $this->assertCount(1, $todayLogs);
        $this->assertEquals($uuid, $todayLogs->first()->id);

        // Test Evening Scan (18:00 WIB = 11:00 UTC)
        $eveningUuid = Str::uuid()->toString();
        $this->postJson('/api/v1/attendance/batch', [
            'records' => [
                [
                    'id' => $eveningUuid,
                    'student_id' => $student->id,
                    'captured_at' => '2026-10-01T11:00:00Z',
                    'direction' => 'out',
                    'score' => 0.95,
                    'liveness_score' => 0.9,
                    'time_source' => 'ntp',
                ]
            ]
        ], ['Authorization' => 'Bearer ' . $token]);

        $rawEvening = \Illuminate\Support\Facades\DB::table('attendance_logs')->where('id', $eveningUuid)->first();
        $this->assertEquals('2026-10-01 11:00:00', $rawEvening->captured_at);

        // Evening scan should ALSO be included in "today" (10-01)
        $todayLogs = AttendanceLog::today()->get();
        $this->assertCount(2, $todayLogs);
    }
}

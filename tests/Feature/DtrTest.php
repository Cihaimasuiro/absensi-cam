<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Domain\Student\Models\Student;
use App\Domain\School\Models\Classroom;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Report\Services\DtrService;
use Carbon\Carbon;
use App\Domain\User\Models\User;
use Spatie\Permission\Models\Role;

class DtrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup timezone
        config(['app.timezone' => 'Asia/Jakarta']);
        
        // Setup Roles
        Role::firstOrCreate(['name' => 'super_admin']);
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
    }

    public function test_dtr_service_logic()
    {
        $school = \App\Domain\School\Models\School::create(['name' => 'Test School']);
        $classroom = Classroom::create([
            'school_id' => $school->id,
            'name' => 'Test Class',
            'start_time' => '07:00:00',
            'late_tolerance_minutes' => 15,
        ]);
        
        $student = Student::create([
            'school_id' => $school->id,
            'classroom_id' => $classroom->id,
            'name' => 'Test Student',
            'code' => 'TEST-01'
        ]);

        $device = \App\Domain\Device\Models\Device::create([
            'name' => 'Test Device',
            'device_code' => 'DEV-1',
            'status' => 'online',
            'building_id' => \App\Domain\School\Models\Building::create([
                'name' => 'Test Building',
                'code' => 'BLD-1',
                'school_id' => $school->id
            ])->id
        ]);

        // Create logs
        $logs = [
            // 1. In time (07:10 - within tolerance) and out at 15:00
            ['captured_at' => '2026-10-01 07:10:00', 'direction' => 'in', 'is_corrected' => false],
            ['captured_at' => '2026-10-01 15:00:00', 'direction' => 'out', 'is_corrected' => false],

            // 2. Late (07:30 - out of tolerance) and out at 15:00
            ['captured_at' => '2026-10-02 07:30:00', 'direction' => 'in', 'is_corrected' => false],
            ['captured_at' => '2026-10-02 15:00:00', 'direction' => 'out', 'is_corrected' => false],

            // 3. Only IN
            ['captured_at' => '2026-10-03 07:00:00', 'direction' => 'in', 'is_corrected' => false],

            // 4. Only OUT (Tanpa Scan Masuk)
            ['captured_at' => '2026-10-04 15:00:00', 'direction' => 'out', 'is_corrected' => false],

            // 5. Corrected log
            ['captured_at' => '2026-10-05 07:00:00', 'direction' => 'in', 'is_corrected' => true],
            
            // 6. WIB boundary (UTC+7). 17:30 UTC is 00:30 WIB next day.
            // 17:30 UTC on 2026-10-06 is 2026-10-07 00:30:00 WIB
            // Let's create raw logs that translate to late-night and early-morning WIB.
            // Actually, we can just supply WIB time string into AttendanceLog which casts it to UTC.
        ];

        foreach ($logs as $logData) {
            AttendanceLog::create([
                'student_id' => $student->id,
                'device_id' => $device->id,
                'captured_at' => Carbon::parse($logData['captured_at'], 'Asia/Jakarta')->setTimezone('UTC'), // Mutator handles it, but let's be explicit just in case. Wait, if we set string, mutator converts it assuming it's UTC if no TZ. Actually mutator receives what we pass. Let's pass UTC directly:
                'direction' => $logData['direction'],
                'method' => 'face',
                'score' => 0.99,
                'is_corrected' => $logData['is_corrected'],
            ]);
        }
        
        // Let's create a WIB boundary test specifically:
        // WIB: 2026-10-06 06:30:00 IN
        // WIB: 2026-10-06 18:00:00 OUT
        AttendanceLog::create([
            'student_id' => $student->id,
            'device_id' => $device->id,
            'captured_at' => Carbon::parse('2026-10-06 06:30:00', 'Asia/Jakarta')->setTimezone('UTC')->toDateTimeString(),
            'direction' => 'in',
            'method' => 'face',
            'score' => 0.99,
            'is_corrected' => false,
        ]);
        AttendanceLog::create([
            'student_id' => $student->id,
            'device_id' => $device->id,
            'captured_at' => Carbon::parse('2026-10-06 18:00:00', 'Asia/Jakarta')->setTimezone('UTC')->toDateTimeString(),
            'direction' => 'out',
            'method' => 'face',
            'score' => 0.99,
            'is_corrected' => false,
        ]);

        $queryLogs = AttendanceLog::with(['student.group'])->orderBy('captured_at')->get();
        
        $service = new DtrService();
        $dtr = $service->generateDtr($queryLogs)->keyBy('date');

        // Assertions
        $this->assertEquals('Hadir', $dtr['2026-10-01']['status']);
        $this->assertEquals(0, $dtr['2026-10-01']['minutes_late']);
        $this->assertEquals(470, $dtr['2026-10-01']['duration_minutes']); // 07:10 to 15:00 = 7 hours 50 mins = 470 mins

        $this->assertEquals('Terlambat', $dtr['2026-10-02']['status']);
        $this->assertEquals(30, $dtr['2026-10-02']['minutes_late']);

        $this->assertEquals('Hadir', $dtr['2026-10-03']['status']);
        $this->assertEquals('-', $dtr['2026-10-03']['last_out']);

        $this->assertEquals('Tanpa Scan Masuk', $dtr['2026-10-04']['status']);
        $this->assertEquals('-', $dtr['2026-10-04']['first_in']);

        $this->assertTrue($dtr['2026-10-05']['is_corrected']);

        $this->assertEquals('06:30:00', $dtr['2026-10-06']['first_in']);
        $this->assertEquals('18:00:00', $dtr['2026-10-06']['last_out']);
        $this->assertEquals('Hadir', $dtr['2026-10-06']['status']);
    }
    
    public function test_dtr_export_roles()
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        
        $this->actingAs($user);
        
        $response = $this->get(route('reports.export.dtr.xlsx'));
        $response->assertStatus(403);
        
        $response = $this->get(route('reports.export.dtr.pdf'));
        $response->assertStatus(403);
        
    }
}

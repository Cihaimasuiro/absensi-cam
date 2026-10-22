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
        
        // Atur timezone
        config(['app.timezone' => 'Asia/Jakarta']);
        
        // Atur Roles
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

        // Buat logs
        $logs = [
            // 1. Masuk tepat waktu (07:10 - dalam toleransi) dan keluar pukul 15:00
            ['captured_at' => '2026-10-01 07:10:00', 'direction' => 'in', 'is_corrected' => false],
            ['captured_at' => '2026-10-01 15:00:00', 'direction' => 'out', 'is_corrected' => false],

            // 2. Terlambat (07:30 - di luar toleransi) dan keluar pukul 15:00
            ['captured_at' => '2026-10-02 07:30:00', 'direction' => 'in', 'is_corrected' => false],
            ['captured_at' => '2026-10-02 15:00:00', 'direction' => 'out', 'is_corrected' => false],

            // 3. Hanya IN
            ['captured_at' => '2026-10-03 07:00:00', 'direction' => 'in', 'is_corrected' => false],

            // 4. Only OUT (Tanpa Scan Masuk)
            ['captured_at' => '2026-10-04 15:00:00', 'direction' => 'out', 'is_corrected' => false],

            // 5. Log yang dikoreksi
            ['captured_at' => '2026-10-05 07:00:00', 'direction' => 'in', 'is_corrected' => true],
            
            // 6. Batas WIB (UTC+7). 17:30 UTC adalah 00:30 WIB hari berikutnya.
            // 17:30 UTC pada 2026-10-06 adalah 2026-10-07 00:30:00 WIB
            // Mari buat raw log yang setara dengan larut malam dan pagi buta WIB.
            // Sebenarnya, kita bisa langsung memberi string waktu WIB ke AttendanceLog yang akan meng-cast-nya ke UTC.
        ];

        foreach ($logs as $logData) {
            AttendanceLog::create([
                'student_id' => $student->id,
                'device_id' => $device->id,
                'captured_at' => Carbon::parse($logData['captured_at'], 'Asia/Jakarta')->setTimezone('UTC'), // Mutator menanganinya, tapi mari eksplisit untuk berjaga-jaga. Tunggu, jika kita set string, mutator mengonversinya dengan asumsi UTC bila tanpa TZ. Sebenarnya mutator menerima apa yang kita kirim. Mari kirim UTC langsung:
                'direction' => $logData['direction'],
                'method' => 'face',
                'score' => 0.99,
                'is_corrected' => $logData['is_corrected'],
            ]);
        }
        
        // Mari buat test batas WIB secara khusus:
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

        // Assertion
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
        
        // Test late_tolerance_minutes null (pelacakan keterlambatan mati)
        $classroomNoLimit = Classroom::create([
            'school_id' => $school->id,
            'name' => 'No Limit Class',
            'start_time' => '07:00:00',
            'late_tolerance_minutes' => null,
        ]);
        $studentNoLimit = Student::create([
            'school_id' => $school->id,
            'classroom_id' => $classroomNoLimit->id,
            'name' => 'Student No Limit',
            'code' => 'TEST-02'
        ]);
        AttendanceLog::create([
            'student_id' => $studentNoLimit->id,
            'device_id' => $device->id,
            'captured_at' => Carbon::parse('2026-10-06 09:00:00', 'Asia/Jakarta')->setTimezone('UTC')->toDateTimeString(),
            'direction' => 'in',
            'method' => 'face',
            'score' => 0.99,
            'is_corrected' => false,
        ]);
        $queryLogsNoLimit = AttendanceLog::with(['student.group'])->where('student_id', $studentNoLimit->id)->get();
        $dtrNoLimit = $service->generateDtr($queryLogsNoLimit)->first();
        $this->assertEquals('Hadir', $dtrNoLimit['status']);
        $this->assertEquals(0, $dtrNoLimit['minutes_late']);
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
    
    public function test_admin_can_export_dtr_and_query_count_is_bounded()
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $school = \App\Domain\School\Models\School::create(['name' => 'Test School']);
        $classroom = Classroom::create([
            'school_id' => $school->id,
            'name' => 'Test Class',
            'start_time' => '07:00:00',
            'late_tolerance_minutes' => 15,
        ]);
        
        $device = \App\Domain\Device\Models\Device::create([
            'name' => 'Test Device',
            'device_code' => 'TEST01',
        ]);

        $createLogs = function ($count) use ($school, $classroom, $device) {
            $start = Student::count();
            for ($i = $start; $i < $start + $count; $i++) {
                $student = Student::create([
                    'school_id' => $school->id,
                    'classroom_id' => $classroom->id,
                    'name' => 'Test Student ' . $i,
                    'code' => 'TEST-' . $i
                ]);

                AttendanceLog::create([
                    'student_id' => $student->id,
                    'device_id' => $device->id,
                    'captured_at' => Carbon::now()->setTimezone('UTC')->toDateTimeString(),
                    'direction' => 'in',
                    'method' => 'face',
                    'score' => 0.99,
                    'is_corrected' => false,
                ]);
            }
        };

        $createLogs(2);

        // Pemanasan query auth/session
        $this->get(route('reports.export.dtr.xlsx', [
            'start' => Carbon::now()->toDateString(),
            'end' => Carbon::now()->toDateString()
        ]));

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Maatwebsite\Excel\Facades\Excel::fake();
        
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $responseXlsx = $this->get(route('reports.export.dtr.xlsx', [
            'start' => Carbon::now()->toDateString(),
            'end' => Carbon::now()->toDateString()
        ]));
        $responseXlsx->assertStatus(200);
        $queries2 = count(\Illuminate\Support\Facades\DB::getQueryLog());
        
        $createLogs(3); // Sekarang kita punya 5 siswa/log
        
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $this->get(route('reports.export.dtr.xlsx', [
            'start' => Carbon::now()->toDateString(),
            'end' => Carbon::now()->toDateString()
        ]));
        $queries5 = count(\Illuminate\Support\Facades\DB::getQueryLog());
        
        // Karena lazy(1000) menarik data per chunk 1000,
        // jumlah query seharusnya terbatas dan tidak naik linear per record (N+1)

        $this->assertEquals($queries2, $queries5, "Query count should not grow linearly with logs when under the chunk size.");

        // test pdf 200 tanpa membuat dompdf crash
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->andReturnSelf();
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('setPaper')->andReturnSelf();
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('download')->andReturn(response('pdf', 200));

        $responsePdf = $this->get(route('reports.export.dtr.pdf', [
            'start' => Carbon::now()->toDateString(),
            'end' => Carbon::now()->toDateString()
        ]));
        $responsePdf->assertStatus(200);
    }

    public function test_dtr_export_date_validation()
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        // test start > end
        $response = $this->get(route('reports.export.dtr.xlsx', [
            'start' => Carbon::now()->addDays(5)->toDateString(),
            'end' => Carbon::now()->toDateString()
        ]));
        $response->assertSessionHasErrors('end');

        // test selisih 30 hari (31 hari inklusif) pada XLSX -> PASS
        $responsePass = $this->get(route('reports.export.dtr.xlsx', [
            'start' => Carbon::now()->subDays(30)->toDateString(),
            'end' => Carbon::now()->toDateString()
        ]));
        $responsePass->assertStatus(200);

        // test selisih 31 hari (32 hari inklusif) pada XLSX -> FAIL
        $responseFail = $this->get(route('reports.export.dtr.xlsx', [
            'start' => Carbon::now()->subDays(31)->toDateString(),
            'end' => Carbon::now()->toDateString()
        ]));
        $responseFail->assertSessionHasErrors('end');
    }

    public function test_dtr_export_formatting_and_pdf_rendering()
    {
        // 1. Test mitigasi map() DtrExport untuk CSV injection
        $dtrRecords = collect([
            'test_key' => [
                'date' => '2026-10-06',
                'name' => '=SUM(1)',
                'code' => '+12345',
                'group' => '-Class',
                'first_in' => '@malicious',
                'last_out' => '15:00',
                'is_corrected' => true,
                'start_time' => '07:00:00',
                'late_tolerance_minutes' => 15,
                'minutes_late' => 0,
                'duration_minutes' => 0,
                'status' => 'Hadir',
            ]
        ]);

        $export = new \App\Domain\Report\Exports\DtrExport($dtrRecords);
        $mapped = $export->map($dtrRecords->first());
        
        $this->assertEquals("'=SUM(1)", $mapped[1]); // name
        $this->assertEquals("'+12345", $mapped[2]); // code
        $this->assertEquals("'-Class", $mapped[3]); // group
        $this->assertEquals("@malicious", $mapped[4]); // first_in (bukan dari user, tidak disanitasi)

        // 2. Test render view PDF yang sebenarnya tanpa crash
        $html = view('reports.dtr_pdf', [
            'records' => $dtrRecords,
            'start' => '2026-10-06',
            'end' => '2026-10-06'
        ])->render();

        $this->assertStringContainsString('2026-10-06', $html);
        $this->assertStringContainsString('=SUM(1)', $html); // view PDF tidak menyanitasi CSV injection, hanya HTML escaping
    }

    public function test_dtr_pdf_export_blocks_unfiltered_requests_over_7_days()
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get('/reports/dtr/pdf?start=2026-10-01&end=2026-10-10');

        $response->assertSessionHasErrors(['end']);
    }

    public function test_dtr_pdf_export_allows_filtered_classroom_requests_within_limit()
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $school = \App\Domain\School\Models\School::create(['name' => 'School Test']);
        $classroom = Classroom::create([
            'school_id' => $school->id,
            'name' => 'Class A',
            'start_time' => '07:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $response = $this->actingAs($admin)->get("/reports/dtr/pdf?start=2026-10-01&end=2026-10-14&classroom_id={$classroom->id}");

        $response->assertSessionHasNoErrors();
        $response->assertStatus(200);
    }
}

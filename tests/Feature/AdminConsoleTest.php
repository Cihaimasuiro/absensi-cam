<?php

namespace Tests\Feature;

use App\Domain\Enrollment\Models\BiometricQuarantine;
use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Student\Models\Consent;
use App\Domain\Student\Models\Student;
use App\Domain\User\Models\User;
use App\Jobs\GenerateFaceEmbedding;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->superAdmin = User::factory()->create([
            'name'     => 'Super Admin',
            'email'    => 'superadmin@example.com',
            'password' => Hash::make('password123'),
        ]);
        $this->superAdmin->assignRole('super_admin');

        $this->regularUser = User::factory()->create([
            'name'     => 'Regular User',
            'email'    => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);
        $this->regularUser->assignRole('user');
    }

    public function test_user_management_crud_and_role_assignment(): void
    {
        $this->actingAs($this->superAdmin);

        // 1. Daftar user
        $response = $this->get(route('admin.users.index'));
        $response->assertOk();
        $response->assertSee('Super Admin');

        // 2. Buat user dengan role
        $createResponse = $this->post(route('admin.users.store'), [
            'name'     => 'Staff Baru',
            'email'    => 'staff@example.com',
            'password' => 'secret12345',
            'role'     => 'admin',
        ]);
        $createResponse->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', ['email' => 'staff@example.com']);

        $staff = User::where('email', 'staff@example.com')->first();
        $this->assertTrue($staff->hasRole('admin'));

        // 3. Update user
        $updateResponse = $this->put(route('admin.users.update', $staff), [
            'name'  => 'Staff Senior',
            'email' => 'staff.senior@example.com',
            'role'  => 'super_admin',
        ]);
        $updateResponse->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', ['email' => 'staff.senior@example.com', 'name' => 'Staff Senior']);
        $staff->refresh();
        $this->assertTrue($staff->hasRole('super_admin'));

        // 4. Tidak boleh menghapus diri sendiri
        $deleteSelf = $this->delete(route('admin.users.destroy', $this->superAdmin));
        $deleteSelf->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $this->superAdmin->id]);

        // 5. Hapus user lain
        $deleteOther = $this->delete(route('admin.users.destroy', $staff));
        $deleteOther->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }

    public function test_privilege_escalation_is_prevented(): void
    {
        // Buat user admin yang diberi permission user.manage secara langsung
        $adminUser = User::factory()->create([
            'name'     => 'Admin Manager',
            'email'    => 'admin.mgr@example.com',
            'password' => Hash::make('password123'),
        ]);
        $adminUser->assignRole('admin');
        $adminUser->givePermissionTo('user.manage');

        $this->actingAs($adminUser);

        // 1. Tidak boleh membuat user dengan role super_admin
        $createAttempt = $this->post(route('admin.users.store'), [
            'name'     => 'Hacker',
            'email'    => 'hacker@example.com',
            'password' => 'secret12345',
            'role'     => 'super_admin',
        ]);
        $createAttempt->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'hacker@example.com']);

        // 2. Tidak boleh menaikkan user yang sudah ada menjadi super_admin
        $otherUser = User::factory()->create(['email' => 'victim@example.com']);
        $otherUser->assignRole('user');

        $updateAttempt = $this->put(route('admin.users.update', $otherUser), [
            'name'  => 'Victim',
            'email' => 'victim@example.com',
            'role'  => 'super_admin',
        ]);
        $updateAttempt->assertSessionHasErrors('role');
        $otherUser->refresh();
        $this->assertFalse($otherUser->hasRole('super_admin'));

        // 3. Tidak boleh mengubah super_admin yang sudah ada
        $tamperAttempt = $this->put(route('admin.users.update', $this->superAdmin), [
            'name'  => 'Compromised Super Admin',
            'email' => 'superadmin@example.com',
            'role'  => 'admin',
        ]);
        $tamperAttempt->assertForbidden();

        // 4. Tidak boleh menghapus super_admin yang sudah ada
        $deleteAttempt = $this->delete(route('admin.users.destroy', $this->superAdmin));
        $deleteAttempt->assertForbidden();
    }

    public function test_regular_user_cannot_access_user_management_or_admin_routes(): void
    {
        $this->actingAs($this->regularUser);

        // Diblokir oleh middleware route group role:super_admin|admin
        $this->get(route('admin.users.index'))->assertForbidden();
        $this->get(route('admin.audit.index'))->assertForbidden();
        $this->get(route('admin.quarantine.index'))->assertForbidden();
    }

    public function test_audit_log_viewer_and_export(): void
    {
        $this->actingAs($this->superAdmin);

        // Picu sebuah activity log
        activity('test_log')
            ->performedOn($this->superAdmin)
            ->causedBy($this->superAdmin)
            ->log('Audit test executed');

        // Lihat audit logs
        $response = $this->get(route('admin.audit.index'));
        $response->assertOk();
        $response->assertSee('Audit test executed');

        // Export audit logs ke CSV
        $export = $this->get(route('admin.audit.export'));
        $export->assertOk();
        $export->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_quarantine_scan_detects_corrupted_templates_and_preserves_unrelated_flags(): void
    {
        $this->actingAs($this->superAdmin);

        $student1 = Student::factory()->create();
        FaceTemplate::create([
            'student_id'     => $student1->id,
            'embedding_enc'  => base64_encode('invalid_ciphertext'),
            'model_version'  => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash1',
        ]);

        // Siswa 2 punya template valid tetapi memiliki flag karantina yang tidak terkait (mis. duplicate_face_detected)
        $student2 = Student::factory()->create();
        $validBlob = app(\App\Domain\Enrollment\Services\FaceEncryptionService::class)->encrypt(
            str_repeat('A', 2048),
            (string) $student2->id,
            '3d9f1f77896fb3d1'
        );
        FaceTemplate::create([
            'student_id'     => $student2->id,
            'embedding_enc'  => base64_encode($validBlob),
            'model_version'  => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash2',
        ]);

        $unrelatedQuarantine = BiometricQuarantine::create([
            'student_id'     => $student2->id,
            'reason'         => 'duplicate_face_detected',
            'status'         => 'quarantined',
            'quarantined_at' => now(),
        ]);

        $scanResponse = $this->post(route('admin.quarantine.scan'));
        $scanResponse->assertRedirect(route('admin.quarantine.index'));

        // Siswa 1 harus dikarantina
        $this->assertDatabaseHas('biometric_quarantines', [
            'student_id' => $student1->id,
            'status'     => 'quarantined',
        ]);

        // Karantina duplikat milik siswa 2 TIDAK BOLEH otomatis diselesaikan oleh scan
        $unrelatedQuarantine->refresh();
        $this->assertEquals('quarantined', $unrelatedQuarantine->status);
    }

    public function test_quarantine_re_enroll_dispatches_background_job(): void
    {
        $this->actingAs($this->superAdmin);

        Storage::fake('local');
        Storage::disk('local')->put('faces/student_q.jpg', 'dummy-photo');

        $student = Student::factory()->create();
        Consent::create([
            'student_id'    => $student->id,
            'guardian_name' => 'Wali Siswa',
            'text_version'  => 'v1.0',
            'recorded_by'   => $this->superAdmin->id,
            'given_at'      => now(),
        ]);

        $quarantine = BiometricQuarantine::create([
            'student_id'     => $student->id,
            'reason'         => 'decryption_failed',
            'status'         => 'quarantined',
            'quarantined_at' => now(),
        ]);

        FaceTemplate::create([
            'student_id'     => $student->id,
            'embedding_enc'  => base64_encode('corrupted'),
            'model_version'  => '3d9f1f77896fb3d1',
            'embedding_hash' => 'old_hash',
            'photo_path'     => 'faces/student_q.jpg',
        ]);

        Queue::fake([GenerateFaceEmbedding::class]);

        $response = $this->post(route('admin.quarantine.re-enroll', $quarantine));
        $response->assertRedirect(route('admin.quarantine.index'));

        Queue::assertPushedOn('enrollments', GenerateFaceEmbedding::class, function ($job) use ($student, $quarantine) {
            return $job->studentId === (string) $student->id && $job->quarantineId === $quarantine->id;
        });

        // Uji bahwa saat handle GenerateFaceEmbedding berjalan, karantina diselesaikan dan actor tercatat
        Process::fake([
            '*' => function ($process) {
                $this->assertContains('--image', $process->command);
                $this->assertContains('--models-dir', $process->command);
                return Process::result(json_encode([
                    'success'       => true,
                    'embedding_b64' => base64_encode(str_repeat('Q', 2048)),
                ]));
            },
        ]);

        $tmpPath = Storage::disk('local')->path('tmp/test_q.jpg');
        @mkdir(dirname($tmpPath), 0777, true);
        file_put_contents($tmpPath, 'test-photo');

        $job = new GenerateFaceEmbedding(
            (string) $student->id,
            $tmpPath,
            'faces/student_q.jpg',
            false,
            '3d9f1f77896fb3d1',
            true,
            $quarantine->id,
            $this->superAdmin->id
        );
        $job->handle();

        $quarantine->refresh();
        $this->assertEquals('resolved', $quarantine->status);
        $this->assertEquals($this->superAdmin->id, $quarantine->resolved_by);
    }

    public function test_quarantine_re_enroll_blocked_without_active_consent(): void
    {
        $this->actingAs($this->superAdmin);

        Storage::fake('local');
        Storage::disk('local')->put('faces/student_no_consent.jpg', 'dummy-photo');

        $student = Student::factory()->create();

        // 1. Consent sama sekali belum diberikan
        $quarantine = BiometricQuarantine::create([
            'student_id'     => $student->id,
            'reason'         => 'decryption_failed',
            'status'         => 'quarantined',
            'quarantined_at' => now(),
        ]);

        FaceTemplate::create([
            'student_id'     => $student->id,
            'embedding_enc'  => base64_encode('corrupted'),
            'model_version'  => '3d9f1f77896fb3d1',
            'embedding_hash' => 'old_hash',
            'photo_path'     => 'faces/student_no_consent.jpg',
        ]);

        Queue::fake([GenerateFaceEmbedding::class]);

        $response = $this->post(route('admin.quarantine.re-enroll', $quarantine));
        $response->assertRedirect();
        $response->assertSessionHas('error');
        Queue::assertNothingPushed();

        // 2. Consent sudah dicabut
        $consent = Consent::create([
            'student_id'    => $student->id,
            'guardian_name' => 'Wali Siswa',
            'text_version'  => 'v1.0',
            'recorded_by'   => $this->superAdmin->id,
            'given_at'      => now()->subDay(),
            'withdrawn_at'  => now(),
        ]);

        $response2 = $this->post(route('admin.quarantine.re-enroll', $quarantine));
        $response2->assertRedirect();
        $response2->assertSessionHas('error');
        Queue::assertNothingPushed();

        // 3. Bahkan jika job ter-dispatch entah bagaimana, handle() harus menolak memproses/memulihkan
        $tmpPath = Storage::disk('local')->path('tmp/test_noconsent.jpg');
        @mkdir(dirname($tmpPath), 0777, true);
        file_put_contents($tmpPath, 'test-photo');

        $template = FaceTemplate::where('student_id', $student->id)->first();
        $template->delete(); // Tombstone hasil soft-delete
        $this->assertTrue($template->fresh()->trashed());

        $job = new GenerateFaceEmbedding(
            (string) $student->id,
            $tmpPath,
            'faces/student_no_consent.jpg',
            true,
            '3d9f1f77896fb3d1',
            true,
            $quarantine->id,
            $this->superAdmin->id
        );
        $job->handle();

        // Template HARUS tetap ter-trash (tidak pernah dihidupkan lagi tanpa consent)
        $this->assertTrue($template->fresh()->trashed());
    }

    public function test_re_enroll_resolves_only_targeted_quarantine_incident(): void
    {
        $this->actingAs($this->superAdmin);

        Storage::fake('local');
        $student = Student::factory()->create();
        Consent::create([
            'student_id'    => $student->id,
            'guardian_name' => 'Wali Siswa',
            'text_version'  => 'v1.0',
            'recorded_by'   => $this->superAdmin->id,
            'given_at'      => now(),
        ]);

        // Karantina 1: Masalah integritas (ditargetkan untuk re-enrollment)
        $integrityQuarantine = BiometricQuarantine::create([
            'student_id'     => $student->id,
            'reason'         => 'decryption_failed',
            'status'         => 'quarantined',
            'quarantined_at' => now(),
        ]);

        // Karantina 2: Review manual / flag duplikat (tidak terkait, TIDAK BOLEH otomatis diselesaikan)
        $unrelatedQuarantine = BiometricQuarantine::create([
            'student_id'     => $student->id,
            'reason'         => 'manual_security_flag',
            'status'         => 'quarantined',
            'quarantined_at' => now(),
        ]);

        Process::fake([
            '*' => function ($process) {
                $this->assertContains('--image', $process->command);
                $this->assertContains('--models-dir', $process->command);
                return Process::result(json_encode([
                    'success'       => true,
                    'embedding_b64' => base64_encode(str_repeat('Q', 2048)),
                ]));
            },
        ]);

        $tmpPath = Storage::disk('local')->path('tmp/target_q.jpg');
        @mkdir(dirname($tmpPath), 0777, true);
        file_put_contents($tmpPath, 'test-photo');

        $job = new GenerateFaceEmbedding(
            (string) $student->id,
            $tmpPath,
            'faces/target.jpg',
            false,
            '3d9f1f77896fb3d1',
            true,
            $integrityQuarantine->id,
            $this->superAdmin->id
        );
        $job->handle();

        $integrityQuarantine->refresh();
        $unrelatedQuarantine->refresh();

        $this->assertEquals('resolved', $integrityQuarantine->status);
        $this->assertEquals($this->superAdmin->id, $integrityQuarantine->resolved_by);
        $this->assertEquals('quarantined', $unrelatedQuarantine->status); // Tetap dipertahankan!
    }

    public function test_generate_face_embedding_preserves_tmp_file_on_retryable_failure(): void
    {
        $student = Student::factory()->create();
        Consent::create([
            'student_id'    => $student->id,
            'guardian_name' => 'Wali Siswa',
            'text_version'  => 'v1.0',
            'recorded_by'   => $this->superAdmin->id,
            'given_at'      => now(),
        ]);

        Storage::fake('local');
        $tmpPath = Storage::disk('local')->path('tmp/retry_test.jpg');
        @mkdir(dirname($tmpPath), 0777, true);
        file_put_contents($tmpPath, 'test-photo');

        // Simulasikan kegagalan ekstraksi pada percobaan 1
        Process::fake([
            '*' => function ($process) {
                $this->assertContains('--image', $process->command);
                $this->assertContains('--models-dir', $process->command);
                return Process::result('', 'Temporary process timeout', 1);
            },
        ]);

        $job = new GenerateFaceEmbedding(
            (string) $student->id,
            $tmpPath,
            'faces/test.jpg',
            true,
            '3d9f1f77896fb3d1',
            true
        );

        try {
            $job->handle();
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            // File tmp HARUS tetap ada untuk percobaan queue ke-2!
            $this->assertFileExists($tmpPath);
        }

        // Saat failed() dipanggil setelah semua retry habis, file tmp dihapus
        $job->failed(new \RuntimeException('All attempts exhausted'));
        $this->assertFileDoesNotExist($tmpPath);
    }

    public function test_quarantine_resolve_validates_notes_length(): void
    {
        $this->actingAs($this->superAdmin);

        $student = Student::factory()->create();
        $quarantine = BiometricQuarantine::create([
            'student_id'     => $student->id,
            'reason'         => 'manual_review',
            'status'         => 'quarantined',
            'quarantined_at' => now(),
        ]);

        $longNotes = str_repeat('A', 501);
        $response = $this->post(route('admin.quarantine.resolve', $quarantine), [
            'notes' => $longNotes,
        ]);
        $response->assertSessionHasErrors('notes');

        $quarantine->refresh();
        $this->assertEquals('quarantined', $quarantine->status);
    }
}

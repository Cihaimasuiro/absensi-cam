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

        // 1. List users
        $response = $this->get(route('admin.users.index'));
        $response->assertOk();
        $response->assertSee('Super Admin');

        // 2. Create user with role
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

        // 4. Cannot delete self
        $deleteSelf = $this->delete(route('admin.users.destroy', $this->superAdmin));
        $deleteSelf->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $this->superAdmin->id]);

        // 5. Delete other user
        $deleteOther = $this->delete(route('admin.users.destroy', $staff));
        $deleteOther->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }

    public function test_privilege_escalation_is_prevented(): void
    {
        // Create an admin user who is granted user.manage permission directly
        $adminUser = User::factory()->create([
            'name'     => 'Admin Manager',
            'email'    => 'admin.mgr@example.com',
            'password' => Hash::make('password123'),
        ]);
        $adminUser->assignRole('admin');
        $adminUser->givePermissionTo('user.manage');

        $this->actingAs($adminUser);

        // 1. Cannot create user with super_admin role
        $createAttempt = $this->post(route('admin.users.store'), [
            'name'     => 'Hacker',
            'email'    => 'hacker@example.com',
            'password' => 'secret12345',
            'role'     => 'super_admin',
        ]);
        $createAttempt->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'hacker@example.com']);

        // 2. Cannot escalate existing user to super_admin
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

        // 3. Cannot modify an existing super_admin
        $tamperAttempt = $this->put(route('admin.users.update', $this->superAdmin), [
            'name'  => 'Compromised Super Admin',
            'email' => 'superadmin@example.com',
            'role'  => 'admin',
        ]);
        $tamperAttempt->assertForbidden();

        // 4. Cannot delete an existing super_admin
        $deleteAttempt = $this->delete(route('admin.users.destroy', $this->superAdmin));
        $deleteAttempt->assertForbidden();
    }

    public function test_regular_user_cannot_access_user_management_or_admin_routes(): void
    {
        $this->actingAs($this->regularUser);

        // Blocked by route group middleware role:super_admin|admin
        $this->get(route('admin.users.index'))->assertForbidden();
        $this->get(route('admin.audit.index'))->assertForbidden();
        $this->get(route('admin.quarantine.index'))->assertForbidden();
        $this->get(route('admin.consents.index'))->assertForbidden();
    }

    public function test_audit_log_viewer_and_export(): void
    {
        $this->actingAs($this->superAdmin);

        // Trigger an activity log
        activity('test_log')
            ->performedOn($this->superAdmin)
            ->causedBy($this->superAdmin)
            ->log('Audit test executed');

        // View audit logs
        $response = $this->get(route('admin.audit.index'));
        $response->assertOk();
        $response->assertSee('Audit test executed');

        // Export audit logs CSV
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

        // Student 2 has valid template but has an unrelated quarantine flag (e.g. duplicate_face_detected)
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

        // Student 1 must be quarantined
        $this->assertDatabaseHas('biometric_quarantines', [
            'student_id' => $student1->id,
            'status'     => 'quarantined',
        ]);

        // Student 2's duplicate quarantine must NOT be auto-resolved by scan
        $unrelatedQuarantine->refresh();
        $this->assertEquals('quarantined', $unrelatedQuarantine->status);
    }

    public function test_quarantine_re_enroll_dispatches_background_job(): void
    {
        $this->actingAs($this->superAdmin);

        Storage::fake('local');
        Storage::disk('local')->put('faces/student_q.jpg', 'dummy-photo');

        $student = Student::factory()->create();
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

        Queue::assertPushedOn('enrollments', GenerateFaceEmbedding::class, function ($job) use ($student) {
            return $job->studentId === (string) $student->id;
        });

        // Test that when GenerateFaceEmbedding handle runs, it resolves the quarantine
        Process::fake([
            '*' => Process::result(json_encode([
                'success'       => true,
                'embedding_b64' => base64_encode(str_repeat('Q', 2048)),
            ])),
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
            true
        );
        $job->handle();

        $quarantine->refresh();
        $this->assertEquals('resolved', $quarantine->status);
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

    public function test_consent_management_grant_withdraw_and_export(): void
    {
        $this->actingAs($this->superAdmin);

        $student = Student::factory()->create();
        $template = FaceTemplate::create([
            'student_id'     => $student->id,
            'embedding_enc'  => base64_encode(str_repeat('E', 2048)),
            'model_version'  => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash',
        ]);

        // 1. Grant consent
        $grantResponse = $this->post(route('admin.consents.store'), [
            'student_id'        => $student->id,
            'guardian_name'     => 'Bpk. Hendra',
            'guardian_relation' => 'Ayah',
            'text_version'      => 'v1.0',
        ]);
        $grantResponse->assertRedirect(route('admin.consents.index'));
        $this->assertDatabaseHas('consents', [
            'student_id'    => $student->id,
            'guardian_name' => 'Bpk. Hendra',
            'withdrawn_at'  => null,
        ]);

        $consent = Consent::where('student_id', $student->id)->first();

        // 2. Withdraw consent - must tombstone faceTemplate
        $withdrawResponse = $this->post(route('admin.consents.withdraw', $consent));
        $withdrawResponse->assertRedirect(route('admin.consents.index'));

        $consent->refresh();
        $this->assertNotNull($consent->withdrawn_at);

        // FaceTemplate must be soft-deleted (tombstoned for edge sync)
        $this->assertSoftDeleted('face_templates', ['id' => $template->id]);

        // 3. Export CSV
        $export = $this->get(route('admin.consents.export'));
        $export->assertOk();
        $export->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }
}

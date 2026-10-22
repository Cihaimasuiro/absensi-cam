<?php

namespace Tests\Feature;

use App\Domain\Enrollment\Models\BiometricQuarantine;
use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Student\Models\Consent;
use App\Domain\Student\Models\Student;
use App\Domain\User\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
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

    public function test_regular_user_cannot_access_user_management(): void
    {
        $this->actingAs($this->regularUser);

        $response = $this->get(route('admin.users.index'));
        $response->assertForbidden();
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

    public function test_quarantine_scan_detects_corrupted_templates(): void
    {
        $this->actingAs($this->superAdmin);

        $student = Student::factory()->create();
        FaceTemplate::create([
            'student_id'     => $student->id,
            'embedding_enc'  => base64_encode('invalid_ciphertext'),
            'model_version'  => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash',
        ]);

        $scanResponse = $this->post(route('admin.quarantine.scan'));
        $scanResponse->assertRedirect(route('admin.quarantine.index'));

        $this->assertDatabaseHas('biometric_quarantines', [
            'student_id' => $student->id,
            'status'     => 'quarantined',
        ]);
    }

    public function test_quarantine_re_enroll_resolves_quarantine(): void
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

        Process::fake([
            '*' => Process::result(json_encode([
                'success'       => true,
                'embedding_b64' => base64_encode(str_repeat('Q', 2048)),
            ])),
        ]);

        $response = $this->post(route('admin.quarantine.re-enroll', $quarantine));
        $response->assertRedirect(route('admin.quarantine.index'));

        $quarantine->refresh();
        $this->assertEquals('resolved', $quarantine->status);
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

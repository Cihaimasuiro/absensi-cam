<?php

namespace Tests\Feature;

use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Student\Models\Student;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsentAndTombstoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_withdrawing_consent_soft_deletes_template_and_increments_cursor(): void
    {
        $user = User::factory()->create();
        $student = Student::factory()->create();

        // 1. Give consent and create a template
        $student->consents()->create([
            'given_at'     => now(),
            'text_version' => 'v1.0',
            'recorded_by'  => $user->id,
        ]);

        $template = FaceTemplate::create([
            'student_id' => $student->id,
            'embedding_enc' => 'enc123',
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash123',
        ]);
        
        $initialCursor = $template->version_cursor;
        $this->assertNotNull($initialCursor);

        $response = $this->actingAs($user)->postJson(route('students.update', $student), [
            'name' => $student->name,
            'code' => $student->code ?? $student->student_number ?? '12345',
            'building_id' => $student->building_id,
            'has_consent' => false,
            '_method' => 'PUT' // because Route::resource usually uses PUT/PATCH
        ]);

        // 3. Verify consent is withdrawn
        $this->assertFalse($student->fresh()->hasActiveConsent());

        // 4. Verify template is soft-deleted
        $this->assertSoftDeleted('face_templates', [
            'id' => $template->id,
        ]);

        // 5. Verify version_cursor is incremented
        $deletedTemplate = FaceTemplate::withTrashed()->find($template->id);
        $this->assertGreaterThan($initialCursor, $deletedTemplate->version_cursor);
    }
}

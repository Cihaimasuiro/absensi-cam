<?php

namespace Tests\Feature;

use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Student\Models\Student;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FaceTemplateCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_view_student_face_photo(): void
    {
        Storage::fake('local');

        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create();
        $user->assignRole('admin');

        $student = Student::factory()->create();
        
        $path = "faces/{$student->id}.jpg";
        Storage::disk('local')->put($path, 'fake_image_content');

        FaceTemplate::create([
            'student_id' => $student->id,
            'embedding_enc' => 'enc123',
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash123',
            'photo_path' => $path,
        ]);

        $response = $this->actingAs($user)->get(route('students.photo', $student));
        $response->assertOk();
    }

    public function test_viewing_nonexistent_photo_returns_404(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create();
        $user->assignRole('admin');

        $student = Student::factory()->create();

        $response = $this->actingAs($user)->get(route('students.photo', $student));
        $response->assertNotFound();
    }

    public function test_can_delete_face_template_via_ajax(): void
    {
        Storage::fake('local');

        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create();
        $user->assignRole('admin');

        $student = Student::factory()->create();
        
        $path = "faces/{$student->id}.jpg";
        Storage::disk('local')->put($path, 'fake_image_content');

        $template = FaceTemplate::create([
            'student_id' => $student->id,
            'embedding_enc' => 'enc123',
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash123',
            'photo_path' => $path,
        ]);

        $response = $this->actingAs($user)->deleteJson(route('students.enroll.destroy', $student));
        
        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSoftDeleted('face_templates', [
            'id' => $template->id,
        ]);

        Storage::disk('local')->assertMissing($path);
    }
}

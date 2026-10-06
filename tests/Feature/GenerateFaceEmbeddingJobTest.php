<?php

namespace Tests\Feature;

use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Student\Models\Student;
use App\Jobs\GenerateFaceEmbedding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;
use App\Domain\School\Models\School;

class GenerateFaceEmbeddingJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_successfully_extracts_and_encrypts_embedding()
    {
        $school = School::factory()->create();
        $student = Student::factory()->create(['school_id' => $school->id]);
        
        $tmpPath = sys_get_temp_dir() . '/test_photo.jpg';
        file_put_contents($tmpPath, 'dummy image data');

        $fakeEmbedding = base64_encode(str_repeat('a', 2048));
        
        Process::fake([
            '*extract.py*' => Process::result(json_encode([
                'success' => true,
                'embedding_b64' => $fakeEmbedding
            ])),
        ]);

        $job = new GenerateFaceEmbedding(
            studentId: $student->id,
            tmpPath: $tmpPath,
            deleteAfter: true,
            modelVersion: '3d9f1f77896fb3d1'
        );
        
        $job->handle();

        $this->assertDatabaseHas('face_templates', [
            'student_id' => $student->id,
            'model_version' => '3d9f1f77896fb3d1'
        ]);

        $template = FaceTemplate::where('student_id', $student->id)->first();
        $this->assertNotNull($template->embedding_enc);
        $this->assertFalse(file_exists($tmpPath)); // Should delete the file
    }
    
    public function test_job_uses_config_model_version_when_null()
    {
        config(['app.model_version' => 'custom-version-123']);
        
        $school = School::factory()->create();
        $student = Student::factory()->create(['school_id' => $school->id]);
        
        $tmpPath = sys_get_temp_dir() . '/test_photo3.jpg';
        file_put_contents($tmpPath, 'dummy image data');

        $fakeEmbedding = base64_encode(str_repeat('b', 2048));
        
        Process::fake([
            '*extract.py*' => Process::result(json_encode([
                'success' => true,
                'embedding_b64' => $fakeEmbedding
            ])),
        ]);

        $job = new GenerateFaceEmbedding(
            studentId: $student->id,
            tmpPath: $tmpPath,
            deleteAfter: true,
            modelVersion: null
        );
        
        $job->handle();

        $this->assertDatabaseHas('face_templates', [
            'student_id' => $student->id,
            'model_version' => 'custom-version-123'
        ]);
        
        @unlink($tmpPath);
    }
    
    public function test_job_fails_when_output_is_invalid()
    {
        $school = School::factory()->create();
        $student = Student::factory()->create(['school_id' => $school->id]);
        
        $tmpPath = sys_get_temp_dir() . '/test_photo2.jpg';
        file_put_contents($tmpPath, 'dummy image data');
        
        Process::fake([
            '*extract.py*' => Process::result(json_encode([
                'success' => false,
                'error' => 'No face detected'
            ])),
        ]);

        $job = new GenerateFaceEmbedding(
            studentId: $student->id,
            tmpPath: $tmpPath,
            deleteAfter: true
        );
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Python extraction error: No face detected');
        
        $job->handle();
    }
}

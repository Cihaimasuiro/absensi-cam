<?php

namespace Tests\Feature;

use App\Domain\Device\Models\Device;
use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\School\Models\Building;
use App\Domain\School\Models\Classroom;
use App\Domain\School\Models\School;
use App\Domain\Student\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildingFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_in_building_without_classrooms_gets_all_templates()
    {
        $school = School::factory()->create();
        $building = Building::factory()->create(['school_id' => $school->id]);
        $device = Device::factory()->create(['building_id' => $building->id]);

        $student = Student::factory()->create(['school_id' => $school->id]);
        $template = FaceTemplate::create([
            'student_id' => $student->id,
            'embedding_enc' => 'enc123',
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash123',
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($device, ['device']);
        $response = $this->getJson('/api/v1/templates');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('items'));
        $this->assertEquals('upsert', $response->json('items.0.op'));
        $this->assertEquals($student->id, $response->json('items.0.student_id'));
    }

    public function test_device_in_building_with_classrooms_gets_only_in_scope_templates()
    {
        $school = School::factory()->create();
        
        $buildingA = Building::factory()->create(['school_id' => $school->id]);
        $classroomA = Classroom::create(['school_id' => $school->id, 'building_id' => $buildingA->id, 'name' => 'Class A']);
        $studentA = Student::factory()->create(['school_id' => $school->id, 'classroom_id' => $classroomA->id]);
        FaceTemplate::create([
            'student_id' => $studentA->id,
            'embedding_enc' => 'enc123',
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash123a',
        ]);

        $buildingB = Building::factory()->create(['school_id' => $school->id]);
        $classroomB = Classroom::create(['school_id' => $school->id, 'building_id' => $buildingB->id, 'name' => 'Class B']);
        $studentB = Student::factory()->create(['school_id' => $school->id, 'classroom_id' => $classroomB->id]);
        FaceTemplate::create([
            'student_id' => $studentB->id,
            'embedding_enc' => 'enc123',
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash123b',
        ]);

        $deviceA = Device::factory()->create(['building_id' => $buildingA->id]);

        \Laravel\Sanctum\Sanctum::actingAs($deviceA, ['device']);
        $response = $this->getJson('/api/v1/templates');

        $response->assertStatus(200);
        
        // Should only return 2 items: studentA is upsert, studentB is delete (out of scope)
        $items = collect($response->json('items'));
        $this->assertCount(2, $items);
        
        $itemA = $items->firstWhere('student_id', $studentA->id);
        $this->assertEquals('upsert', $itemA['op']);

        $itemB = $items->firstWhere('student_id', $studentB->id);
        $this->assertEquals('delete', $itemB['op']);
    }

    public function test_template_version_cursor_bumps_when_student_moves_out_of_scope()
    {
        $school = School::factory()->create();
        
        $buildingA = Building::factory()->create(['school_id' => $school->id]);
        $classroomA = Classroom::create(['school_id' => $school->id, 'building_id' => $buildingA->id, 'name' => 'Class A']);
        
        $buildingB = Building::factory()->create(['school_id' => $school->id]);
        $classroomB = Classroom::create(['school_id' => $school->id, 'building_id' => $buildingB->id, 'name' => 'Class B']);
        
        $student = Student::factory()->create(['school_id' => $school->id, 'classroom_id' => $classroomA->id]);
        $template = FaceTemplate::create([
            'student_id' => $student->id,
            'embedding_enc' => 'enc123',
            'model_version' => '3d9f1f77896fb3d1',
            'embedding_hash' => 'hash123',
        ]);
        
        $originalCursor = $template->version_cursor;

        // Move student to classroom B
        $student->update(['classroom_id' => $classroomB->id]);
        
        $template->refresh();
        $this->assertGreaterThan($originalCursor, $template->version_cursor);

        // Fetch templates for Device A (since cursor bumped, it should see it as a delete)
        $deviceA = Device::factory()->create(['building_id' => $buildingA->id]);
        \Laravel\Sanctum\Sanctum::actingAs($deviceA, ['device']);
        $responseA = $this->getJson("/api/v1/templates?cursor={$originalCursor}");

        $itemsA = collect($responseA->json('items'));
        $this->assertCount(1, $itemsA);
        $this->assertEquals('delete', $itemsA->first()['op']);

        // Fetch templates for Device B (should see it as an upsert)
        $deviceB = Device::factory()->create(['building_id' => $buildingB->id]);
        \Laravel\Sanctum\Sanctum::actingAs($deviceB, ['device']);
        $responseB = $this->getJson("/api/v1/templates?cursor={$originalCursor}");

        $itemsB = collect($responseB->json('items'));
        $this->assertCount(1, $itemsB);
        $this->assertEquals('upsert', $itemsB->first()['op']);
    }
}

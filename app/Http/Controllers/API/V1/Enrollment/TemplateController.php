<?php

namespace App\Http\Controllers\API\V1\Enrollment;

use App\Domain\Device\Models\Device;
use App\Domain\Enrollment\Models\FaceTemplate;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/templates?cursor=<N>&limit=200
 *
 * Delta sync of face templates to edge (FR-S07, FR-E11, PRD §11.2).
 * Returns templates with version_cursor > given cursor, including tombstones (op=delete).
 * Edge filters by model_version and rejects incompatible ones (AC-45).
 */
class TemplateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $cursor = (int) $request->query('cursor', 0);
        $limit  = min((int) $request->query('limit', 200), 200);

        // Fetch active templates + soft-deleted tombstones
        // Limited per school (gedung) as requested
        $schoolId = $device->building?->school_id;

        // Determine if the device's building has any classrooms.
        // If it has classrooms, only templates for students in those classrooms are 'in-scope'.
        // If it has NO classrooms (gate device), ALL templates for the school are 'in-scope'.
        $buildingId = $device->building_id;
        $buildingHasClassrooms = $buildingId ? \App\Domain\School\Models\Classroom::where('building_id', $buildingId)->exists() : false;

        $templates = FaceTemplate::withTrashed()
            ->with('student:id,name,school_id,classroom_id')
            ->whereHas('student', function ($query) use ($schoolId) {
                $query->withTrashed();
                if ($schoolId) {
                    $query->where('school_id', $schoolId);
                }
            })
            ->where('version_cursor', '>', $cursor)
            ->orderBy('version_cursor')
            ->limit($limit)
            ->get(['id', 'student_id', 'embedding_enc', 'model_version', 'version_cursor', 'deleted_at', 'updated_at']);

        // Eager load the building_id of the students' classrooms to evaluate scope efficiently
        $classroomIds = $templates->pluck('student.classroom_id')->filter()->unique();
        $classroomBuildings = \App\Domain\School\Models\Classroom::whereIn('id', $classroomIds)->pluck('building_id', 'id');

        $items = $templates->map(function (FaceTemplate $t) use ($buildingHasClassrooms, $buildingId, $classroomBuildings) {
            $student = $t->student;
            $studentBuildingId = $student ? $classroomBuildings->get($student->classroom_id) : null;
            
            $inScope = true;
            if ($buildingHasClassrooms && $buildingId) {
                $inScope = ($studentBuildingId === $buildingId);
            }

            // If the template is soft-deleted, OR if it's out of scope for this building, tell the device to delete it.
            $op = ($t->deleted_at || !$inScope) ? 'delete' : 'upsert';

            return [
                'student_id'     => $t->student_id,
                'name'           => $student?->name ?? 'Anggota',
                'embedding_b64'  => $op === 'delete' ? null : $t->embedding_enc,
                'model_version'  => $t->model_version,
                'version_cursor' => $t->version_cursor,
                'op'             => $op,
                'updated_at'     => $t->updated_at->toISOString(),
            ];
        });

        $nextCursor = $templates->isNotEmpty()
            ? $templates->last()->version_cursor
            : $cursor;

        return response()->json([
            'model_version' => $device->model_version,
            'items'         => $items,
            'next_cursor'   => $nextCursor,
        ]);
    }
}

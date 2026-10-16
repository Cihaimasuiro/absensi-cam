<?php

namespace App\Http\Controllers\API\V1\Device;

use App\Domain\Device\Actions\RecordHeartbeat;
use App\Domain\Device\Models\Device;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\Device\HeartbeatRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/devices/heartbeat
 *
 * Requires: Authorization: Bearer <device_token> with ability 'device'.
 * Edge sends every 60 seconds (FR-E12). Device offline if silent > 3 min (PRD §1.4).
 */
class DeviceHeartbeatController extends Controller
{
    public function __construct(private readonly RecordHeartbeat $recordHeartbeat) {}

    public function __invoke(HeartbeatRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $this->recordHeartbeat->execute($device, $request->validated());

        $action = 'none';
        $serverHash = null;

        if ($request->filled('templates_hash')) {
            $reportedHash = $request->input('templates_hash');

            $schoolId = $device->school_id ?? $device->building?->school_id;
            $buildingId = $device->building_id;

            $buildingHasClassrooms = false;
            if ($buildingId) {
                $buildingHasClassrooms = \App\Domain\School\Models\Classroom::where('building_id', $buildingId)->exists();
            }

            $templates = \App\Domain\Enrollment\Models\FaceTemplate::whereNull('deleted_at')
                ->with('student:id,name,school_id,classroom_id')
                ->whereHas('student', function ($query) use ($schoolId) {
                    $query->whereNull('deleted_at');
                    if ($schoolId) {
                        $query->where('school_id', $schoolId);
                    }
                })
                ->get(['id', 'student_id', 'version_cursor']);

            $classroomBuildings = \App\Domain\School\Models\Classroom::whereNotNull('building_id')->pluck('building_id', 'id');

            $validTemplates = $templates->filter(function (\App\Domain\Enrollment\Models\FaceTemplate $t) use ($buildingHasClassrooms, $buildingId, $classroomBuildings) {
                if (!$buildingHasClassrooms || !$buildingId) {
                    return true;
                }
                $student = $t->student;
                $studentBuildingId = $student ? $classroomBuildings->get($student->classroom_id) : null;
                return $studentBuildingId === $buildingId;
            })->sortBy('student_id');

            $combined = '';
            foreach ($validTemplates as $t) {
                $combined .= $t->student_id . ':' . ($t->version_cursor ?? 1) . "\n";
            }
            $serverHash = hash('sha256', $combined);

            if ($reportedHash !== $serverHash) {
                $action = 'reconcile_templates';
            }
        }

        return response()->json([
            'ok' => true,
            'action' => $action,
            'server_hash' => $serverHash,
        ]);
    }
}

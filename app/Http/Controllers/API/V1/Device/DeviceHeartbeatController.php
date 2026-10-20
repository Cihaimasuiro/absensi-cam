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

            // Aggregate query for count and max cursor
            $scopeQuery = \Illuminate\Support\Facades\DB::table('face_templates')
                ->join('students', 'students.id', '=', 'face_templates.student_id')
                ->whereNull('face_templates.deleted_at')
                ->whereNull('students.deleted_at');

            if ($schoolId) {
                $scopeQuery->where('students.school_id', $schoolId);
            }

            if ($buildingId && $buildingHasClassrooms) {
                $scopeQuery->join('classrooms', 'classrooms.id', '=', 'students.classroom_id')
                    ->where('classrooms.building_id', $buildingId);
            }

            $meta = $scopeQuery->selectRaw('COUNT(*) as total_count, MAX(face_templates.version_cursor) as max_cursor')->first();
            $count = $meta->total_count ?? 0;
            $maxCursor = $meta->max_cursor ?? 0;

            // Scoped cache key with invalidation epoch, count, max_cursor, and 60-second TTL
            $epoch = \Illuminate\Support\Facades\Cache::get('templates_hash_epoch', 1);
            $cacheKey = "device_templates_hash:e{$epoch}:s" . ($schoolId ?? 'all') . ":b" . ($buildingId ?? 'all') . ":c{$count}:m{$maxCursor}";

            $serverHash = \Illuminate\Support\Facades\Cache::remember($cacheKey, 60, function () use ($schoolId, $buildingId, $buildingHasClassrooms) {
                $query = \Illuminate\Support\Facades\DB::table('face_templates')
                    ->join('students', 'students.id', '=', 'face_templates.student_id')
                    ->whereNull('face_templates.deleted_at')
                    ->whereNull('students.deleted_at');

                if ($schoolId) {
                    $query->where('students.school_id', $schoolId);
                }

                if ($buildingId && $buildingHasClassrooms) {
                    $query->join('classrooms', 'classrooms.id', '=', 'students.classroom_id')
                        ->where('classrooms.building_id', $buildingId);
                }

                $records = $query->orderBy('face_templates.student_id')
                    ->select(['face_templates.student_id', 'face_templates.version_cursor'])
                    ->get();

                $combined = '';
                foreach ($records as $r) {
                    $combined .= $r->student_id . ':' . ($r->version_cursor ?? 1) . "\n";
                }
                return hash('sha256', $combined);
            });

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

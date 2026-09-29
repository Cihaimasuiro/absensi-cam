<?php

namespace App\Http\Controllers\API\V1;

use App\Domain\Attendance\Models\Attendance;
use App\Domain\Member\Models\Member;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\SyncAttendanceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceSyncController extends Controller
{
    /**
     * Receive attendance logs synced from Orange Pi Lite 2 Edge Node.
     *
     * Payload example:
     * {
     *   "device_id": "ORANGEPI-001",
     *   "records": [
     *     { "name": "Budi", "department": "IT", "timestamp": "2026-09-29 08:00:00", "confidence": 0.92 }
     *   ]
     * }
     */
    public function syncAttendance(SyncAttendanceRequest $request): JsonResponse
    {
        $validated   = $request->validated();
        $deviceId    = $validated['device_id'];
        $records     = $validated['records'];
        $syncedCount = 0;

        DB::transaction(function () use ($records, $deviceId, &$syncedCount) {
            foreach ($records as $record) {
                // Coba cocokkan ke Member yang terdaftar berdasarkan nama
                $member = Member::active()
                    ->where('name', $record['name'])
                    ->select(['id', 'name', 'department'])
                    ->first();

                $created = Attendance::firstOrCreate(
                    [
                        'name'        => $record['name'],
                        'device_id'   => $deviceId,
                        'attended_at' => $record['timestamp'],
                    ],
                    [
                        'member_id'  => $member?->id,
                        'department' => $record['department'] ?? $member?->department ?? 'Umum',
                        'confidence' => $record['confidence'] ?? null,
                        'status'     => 'hadir',
                    ]
                );

                if ($created->wasRecentlyCreated) {
                    $syncedCount++;
                }
            }
        });

        Log::info('Edge Node Sync', [
            'device_id'    => $deviceId,
            'total_sent'   => count($records),
            'new_inserted' => $syncedCount,
        ]);

        return response()->json([
            'status'       => 'success',
            'message'      => 'Attendance records synced',
            'synced_count' => $syncedCount,
            'total_sent'   => count($records),
        ]);
    }

    /**
     * Return registered face member list to Edge Node for local template matching.
     * face_embedding is excluded from Member $hidden, exposed only here.
     */
    public function getTemplates(): JsonResponse
    {
        $members = Member::active()
            ->select(['id', 'employee_number', 'name', 'department', 'branch', 'embedding_updated_at'])
            ->withoutGlobalScopes()
            ->get();

        return response()->json([
            'status' => 'success',
            'count'  => $members->count(),
            'data'   => $members,
        ]);
    }
}

<?php

namespace App\Http\Controllers\API\V1\Attendance;

use App\Domain\Attendance\Actions\SyncAttendanceBatch;
use App\Domain\Device\Models\Device;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\Attendance\AttendanceBatchRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/attendance/batch
 *
 * Insert batch idempoten dari outbox edge.
 * Maksimal 200 record per pemanggilan (FR-E10).
 * Response per record: created | duplicate | rejected (PRD §11.2).
 */
class AttendanceBatchController extends Controller
{
    public function __construct(private readonly SyncAttendanceBatch $syncBatch) {}

    public function __invoke(AttendanceBatchRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $results = $this->syncBatch->execute($device, $request->validated()['records']);

        return response()->json(['results' => $results]);
    }
}

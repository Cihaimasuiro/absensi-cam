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

        return response()->json(['ok' => true]);
    }
}

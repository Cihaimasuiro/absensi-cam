<?php

namespace App\Http\Controllers\API\V1\Device;

use App\Domain\Device\Models\Device;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/config
 *
 * Returns device-specific configuration (FR-E08, FR-E10).
 * Includes ETag for conditional GET support — edge skips body parse on 304.
 */
class DeviceConfigController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        // Config is sourced from the device's building/group settings
        // Send timing per group since a device can serve multiple classrooms/staff
        $groups = $device->groups()->orderBy('id')->get();
        $firstGroup = $groups->first();
        
        $config = [
            'cooldown_seconds'          => 60,
            'groups'                    => $groups->map(fn($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'type' => $g->type,
                'start_time' => $g->start_time,
                'late_tolerance_minutes' => $g->late_tolerance_minutes,
                'track_checkout' => (bool)$g->track_checkout,
            ])->toArray(),
            'confidence_threshold'      => 0.363, // TODO: To be calibrated on M0 hardware (AC-22)
            'liveness_enabled'          => (bool)$device->liveness_enabled,
            'max_faces_per_frame'       => 5,
            'mode'                      => 'both', // in|out|both
        ];

        $etag = '"' . md5(json_encode($config)) . '"';

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304);
        }

        return response()->json($config)->header('ETag', $etag);
    }
}

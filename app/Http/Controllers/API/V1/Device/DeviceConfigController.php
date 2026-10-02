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
        // ponytail: flat config array for now; per-device config table if needed later
        $config = [
            'cooldown_seconds'          => 60,
            'late_threshold_minutes'    => $device->building?->devices?->first()?->id
                ? ($device->groups()->first()?->late_threshold_minutes ?? 15)
                : 15,
            'late_threshold_enabled'    => $device->groups()->first()?->late_threshold_enabled ?? false,
            'class_start_time'          => $device->groups()->first()?->class_start_time ?? '08:00',
            'track_checkout'            => $device->groups()->first()?->track_checkout ?? false,
            'confidence_threshold'      => 0.363, // SFace default (AC-22)
            'liveness_enabled'          => true,
            'max_faces_per_frame'       => 5,
            'mode'                      => 'both', // in|out|both
        ];

        $etag = md5(json_encode($config));

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304);
        }

        return response()->json($config)->header('ETag', $etag);
    }
}

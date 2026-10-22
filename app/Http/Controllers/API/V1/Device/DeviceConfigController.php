<?php

namespace App\Http\Controllers\API\V1\Device;

use App\Domain\Device\Models\Device;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/config
 *
 * Mengembalikan konfigurasi khusus device (FR-E08, FR-E10).
 * Menyertakan ETag untuk mendukung conditional GET — edge melewati parsing body saat 304.
 */
class DeviceConfigController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        // Config bersumber dari pengaturan building/group milik device
        // Kirim timing per group karena satu device dapat melayani beberapa classroom/staff
        $groups = $device->groups()->orderBy('id')->get();
        $firstGroup = $groups->first();
        
        $config = [
            'cooldown_seconds'          => 10,
            'groups'                    => $groups->map(fn($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'type' => $g->type,
                'start_time' => $g->start_time,
                'late_tolerance_minutes' => $g->late_tolerance_minutes,
                'track_checkout' => (bool)$g->track_checkout,
            ])->toArray(),
            'confidence_threshold'      => 0.363, // TODO: Perlu dikalibrasi pada hardware M0 (AC-22)
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

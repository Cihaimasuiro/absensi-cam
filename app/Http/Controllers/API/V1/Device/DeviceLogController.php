<?php

namespace App\Http\Controllers\API\V1\Device;

use App\Domain\Device\Models\Device;
use App\Domain\Device\Models\DeviceLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/logs
 *
 * Optional endpoint — edge ships error/warn logs for server-side visibility (FR-E12, NFR-06).
 * Server retains for 7 days (PRD §12.3). Batch: up to 50 log lines per call.
 */
class DeviceLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $validated = $request->validate([
            'logs'               => ['required', 'array', 'min:1', 'max:50'],
            'logs.*.level'       => ['required', 'in:debug,info,warn,error'],
            'logs.*.message'     => ['required', 'string', 'max:1000'],
            'logs.*.context'     => ['nullable', 'array'],
            'logs.*.logged_at'   => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
        ]);

        $now = now();
        $rows = array_map(fn ($log) => [
            'device_id'   => $device->id,
            'level'       => $log['level'],
            'message'     => $log['message'],
            'context'     => isset($log['context']) ? json_encode($log['context']) : null,
            'logged_at'   => $log['logged_at'],
            'received_at' => $now,
        ], $validated['logs']);

        DeviceLog::insert($rows);

        return response()->json(['accepted' => count($rows)]);
    }
}

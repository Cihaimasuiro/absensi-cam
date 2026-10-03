<?php

namespace App\Http\Controllers\API\V1\Device;

use App\Domain\Device\Actions\PairDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\Device\PairDeviceRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/devices/pair
 *
 * Public endpoint — no Bearer token required.
 * Rate-limited to 5 req/min to prevent brute-force on 8-char code space.
 */
class DevicePairController extends Controller
{
    public function __construct(private readonly PairDevice $pairDevice) {}

    public function __invoke(PairDeviceRequest $request): JsonResponse
    {
        $result = $this->pairDevice->execute($request->validated());

        return response()->json($result, 201);
    }
}

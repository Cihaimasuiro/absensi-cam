<?php

namespace App\Http\Controllers\API\V1\Device;

use App\Domain\Device\Actions\PairDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\Device\PairDeviceRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/devices/pair
 *
 * Endpoint publik — tidak memerlukan Bearer token.
 * Dibatasi 5 req/menit untuk mencegah brute-force pada ruang kode 8 karakter.
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

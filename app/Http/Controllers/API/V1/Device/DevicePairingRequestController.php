<?php

namespace App\Http\Controllers\API\V1\Device;

use App\Domain\Device\Actions\CreateDevicePairingRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\Device\StoreDevicePairingRequest;
use Illuminate\Http\JsonResponse;

class DevicePairingRequestController extends Controller
{
    public function __invoke(
        StoreDevicePairingRequest $request,
        CreateDevicePairingRequest $action
    ): JsonResponse {
        $result = $action->execute($request->validated(), $request->ip());

        return response()->json($result, 201);
    }
}

<?php

namespace App\Http\Controllers\API\V1\Device;

use App\Domain\Device\Actions\PollDevicePairing;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\Device\PollDevicePairingRequest;
use Illuminate\Http\JsonResponse;

class DevicePairingPollController extends Controller
{
    public function __invoke(
        PollDevicePairingRequest $request,
        PollDevicePairing $action
    ): JsonResponse {
        $result = $action->execute($request->validated('poll_token'));

        return response()->json($result['data'], $result['http_status']);
    }
}

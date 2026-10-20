<?php

use App\Http\Controllers\API\V1\Attendance\AttendanceBatchController;
use App\Http\Controllers\API\V1\Device\DeviceConfigController;
use App\Http\Controllers\API\V1\Device\DeviceHeartbeatController;
use App\Http\Controllers\API\V1\Device\DeviceLogController;
use App\Http\Controllers\API\V1\Device\DevicePairController;
use App\Http\Controllers\API\V1\Device\DevicePairingPollController;
use App\Http\Controllers\API\V1\Device\DevicePairingRequestController;
use App\Http\Controllers\API\V1\Enrollment\TemplateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — /api/v1
|--------------------------------------------------------------------------
|
| Device API (PRD §11.2):
|   POST   /api/v1/devices/pair        → DevicePairController   (public, rate limited)
|   POST   /api/v1/devices/heartbeat   → DeviceHeartbeatController (Bearer device token)
|   POST   /api/v1/attendance/batch    → AttendanceBatchController  (Bearer device token)
|   GET    /api/v1/templates           → TemplateController         (Bearer device token)
|   GET    /api/v1/config              → DeviceConfigController     (Bearer device token)
|   POST   /api/v1/logs                → DeviceLogController        (Bearer device token)
|
*/

// --- Health Check for Edge Engine & Monitoring ---
Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('api.health');

Route::prefix('v1')->group(function () {

    // --- Device Pairing (legacy public, strict rate limit: 5/min per IP) ---
    Route::post('devices/pair', DevicePairController::class)
        ->middleware(['throttle:5,1'])
        ->name('api.v1.devices.pair');

    // --- Device-Initiated Pairing (public, throttled per IP) ---
    Route::post('devices/pairing/request', DevicePairingRequestController::class)
        ->middleware(['throttle:5,10'])
        ->name('api.v1.devices.pairing.request');

    Route::post('devices/pairing/poll', DevicePairingPollController::class)
        ->middleware(['throttle:60,1'])
        ->name('api.v1.devices.pairing.poll');

    // --- Device API (requires Sanctum token with 'device' ability) ---
    Route::middleware(['auth:sanctum', 'ability:device'])->group(function () {

        Route::post('devices/heartbeat', DeviceHeartbeatController::class)
            ->name('api.v1.devices.heartbeat');

        Route::post('attendance/batch', AttendanceBatchController::class)
            ->name('api.v1.attendance.batch');

        Route::get('templates/snapshot', [TemplateController::class, 'snapshot'])
            ->name('api.v1.templates.snapshot');

        Route::get('templates', TemplateController::class)
            ->name('api.v1.templates');

        Route::get('config', DeviceConfigController::class)
            ->name('api.v1.config');

        Route::post('logs', DeviceLogController::class)
            ->name('api.v1.logs');
    });
});

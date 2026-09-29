<?php

use App\Http\Controllers\API\V1\AttendanceSyncController;
use App\Http\Controllers\API\V1\MemberController;
use App\Http\Controllers\API\V1\User\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // --- User Management (authenticated) ---
    Route::apiResource('users', UserController::class)->only(['index', 'store']);

    // --- Member Management (admin CRUD) ---
    Route::apiResource('members', MemberController::class);

    // --- Edge Node Sync Endpoints (Orange Pi Lite 2 <-> Laravel Admin PC) ---
    // Diakses oleh edge node dengan Sanctum API token
    Route::prefix('sync')->group(function () {
        // Edge node POST attendance records ke sini setelah face recognition
        Route::post('/attendance', [AttendanceSyncController::class, 'syncAttendance']);

        // Edge node GET daftar member + embedding untuk local matching
        Route::get('/templates', [AttendanceSyncController::class, 'getTemplates']);
    });
});

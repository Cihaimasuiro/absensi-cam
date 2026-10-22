<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DeviceController;
use App\Http\Controllers\Web\StudentWebController;
use App\Http\Controllers\Web\SchoolController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\AttendanceCorrectionController;
use App\Http\Controllers\Web\LandingController;
use Illuminate\Support\Facades\Route;

// Public Landing Page
Route::get('/', [LandingController::class, 'index'])->name('landing');

// Auth Routes
Route::get('login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.post');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    // Dashboard / Live Camera Monitor
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('live-camera', [DashboardController::class, 'index'])->name('live-camera.index');
    Route::get('live-camera/recent', [DashboardController::class, 'recentLogs'])->name('live-camera.recent');

    // Students — full CRUD + enrollment
    // Students
    Route::middleware('role:super_admin|admin')->group(function () {
        Route::get('students/template', [StudentWebController::class, 'downloadTemplate'])->name('students.template');
        Route::post('students/import', [StudentWebController::class, 'import'])->name('students.import');
        Route::post('students/bulk-enroll', [StudentWebController::class, 'bulkEnroll'])->name('students.bulk-enroll');
        Route::post('students/{student}/enroll', [StudentWebController::class, 'enrollStore'])->name('students.enroll.store');
        Route::delete('students/{student}/enroll', [StudentWebController::class, 'enrollDestroy'])->name('students.enroll.destroy');
        Route::resource('students', StudentWebController::class)->except(['index', 'show']);
    });
    Route::get('students/{student}/photo', [StudentWebController::class, 'photo'])->name('students.photo');
    Route::resource('students', StudentWebController::class)->only(['index', 'show']);

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/',    [ReportController::class, 'index'])->name('index');
        Route::get('/student-logs', [ReportController::class, 'studentLogs'])->name('student-logs');
        Route::middleware('role:super_admin|admin')->group(function () {
            Route::get('/csv', [ReportController::class, 'exportCsv'])->name('export.csv');
            Route::get('/dtr/xlsx', [ReportController::class, 'exportDtr'])->name('export.dtr.xlsx');
            Route::get('/dtr/pdf', [ReportController::class, 'exportDtrPdf'])->name('export.dtr.pdf');
            Route::post('/logs/{log}/correct', [AttendanceCorrectionController::class, 'store'])->name('logs.correct');
        });
    });

    // Devices / Pairing
    Route::prefix('devices')->name('devices.')->middleware('role:super_admin|admin')->group(function () {
        Route::get('/', [DeviceController::class, 'index'])->name('index');
        Route::post('/', [DeviceController::class, 'store'])->name('store');
        Route::put('/{device}', [DeviceController::class, 'update'])->name('update');
        Route::post('/{device}/reset-token', [DeviceController::class, 'resetToken'])->name('reset-token');
        Route::post('/pairing/{pairingRequest}/approve', [DeviceController::class, 'approvePairing'])->name('pairing.approve');
        Route::post('/pairing/{pairingRequest}/reject', [DeviceController::class, 'rejectPairing'])->name('pairing.reject');
        Route::delete('/{device}/revoke', [DeviceController::class, 'revoke'])->name('revoke');
        Route::delete('/{device}', [DeviceController::class, 'destroy'])->name('destroy');
    });

    // Schools, Classrooms, Buildings
    Route::prefix('schools')->name('schools.')->middleware('role:super_admin|admin')->group(function () {
        Route::get('/',                    [SchoolController::class, 'index'])->name('index');
        Route::post('/',                   [SchoolController::class, 'store'])->name('store');
        Route::put('/{school}',            [SchoolController::class, 'update'])->name('update');
        Route::delete('/{school}',         [SchoolController::class, 'destroy'])->name('destroy');
        Route::post('/classrooms',             [SchoolController::class, 'storeGroup'])->name('classrooms.store');
        Route::put('/classrooms/{classroom}',  [SchoolController::class, 'updateGroup'])->name('classrooms.update');
        Route::put('/{school}/classrooms/timing', [SchoolController::class, 'bulkUpdateTiming'])->name('classrooms.bulkUpdateTiming');
        Route::post('/buildings',           [SchoolController::class, 'storeBuilding'])->name('buildings.store');
    });

    // Admin Console
    Route::prefix('admin')->name('admin.')->middleware('role:super_admin|admin')->group(function () {
        // User CRUD + role assignment
        Route::resource('users', \App\Http\Controllers\Web\Admin\UserController::class)->except(['create', 'show', 'edit']);

        // Audit log viewer
        Route::get('audit-logs', [\App\Http\Controllers\Web\Admin\AuditLogController::class, 'index'])->name('audit.index');
        Route::get('audit-logs/export', [\App\Http\Controllers\Web\Admin\AuditLogController::class, 'exportCsv'])->name('audit.export');

        // Quarantine review & re-enroll
        Route::get('quarantine', [\App\Http\Controllers\Web\Admin\QuarantineController::class, 'index'])->name('quarantine.index');
        Route::post('quarantine/scan', [\App\Http\Controllers\Web\Admin\QuarantineController::class, 'scan'])->name('quarantine.scan');
        Route::post('quarantine/{quarantine}/re-enroll', [\App\Http\Controllers\Web\Admin\QuarantineController::class, 'reEnroll'])->name('quarantine.re-enroll');
        Route::post('quarantine/{quarantine}/resolve', [\App\Http\Controllers\Web\Admin\QuarantineController::class, 'resolve'])->name('quarantine.resolve');

        // Consent management (UU PDP No. 27/2022)
        Route::get('consents', [\App\Http\Controllers\Web\Admin\ConsentController::class, 'index'])->name('consents.index');
        Route::post('consents', [\App\Http\Controllers\Web\Admin\ConsentController::class, 'store'])->name('consents.store');
        Route::post('consents/{consent}/withdraw', [\App\Http\Controllers\Web\Admin\ConsentController::class, 'withdraw'])->name('consents.withdraw');
        Route::get('consents/export', [\App\Http\Controllers\Web\Admin\ConsentController::class, 'exportCsv'])->name('consents.export');
    });
});


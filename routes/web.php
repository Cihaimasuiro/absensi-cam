<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DeviceController;
use App\Http\Controllers\Web\StudentWebController;
use App\Http\Controllers\Web\SchoolController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\AttendanceCorrectionController;
use Illuminate\Support\Facades\Route;

// Auth Routes
Route::get('login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.post');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Students — full CRUD + enrollment
    // Students
    Route::middleware('role:super_admin|admin')->group(function () {
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
});

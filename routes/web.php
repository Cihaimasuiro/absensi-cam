<?php

use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DeviceController;
use App\Http\Controllers\Web\StudentWebController;
use App\Http\Controllers\Web\SchoolController;
use App\Http\Controllers\Web\ReportController;
use Illuminate\Support\Facades\Route;

// Dashboard
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Students — full CRUD + enrollment
Route::post('students/import', [StudentWebController::class, 'import'])->name('students.import');
Route::resource('students', StudentWebController::class);
Route::post('students/{student}/enroll', [StudentWebController::class, 'enrollStore'])->name('students.enroll.store');

// Reports
Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('/',    [ReportController::class, 'index'])->name('index');
    Route::get('/csv', [ReportController::class, 'exportCsv'])->name('export.csv');
});

// Devices / Pairing
Route::prefix('devices')->name('devices.')->group(function () {
    Route::get('/', [DeviceController::class, 'index'])->name('index');
    Route::post('/pairing-code', [DeviceController::class, 'generatePairingCode'])->name('pair.code');
    Route::delete('/{device}/revoke', [DeviceController::class, 'revoke'])->name('revoke');
    
    // Auto Discovery
    Route::get('/discover', [\App\Http\Controllers\Web\DeviceDiscoveryController::class, 'scan'])->name('discover');
    Route::post('/auto-pair', [\App\Http\Controllers\Web\DeviceDiscoveryController::class, 'autoPair'])->name('auto-pair');
});

// Schools, Classrooms, Buildings
Route::prefix('schools')->name('schools.')->group(function () {
    Route::get('/',                    [SchoolController::class, 'index'])->name('index');
    Route::post('/',                   [SchoolController::class, 'store'])->name('store');
    Route::post('/classrooms',             [SchoolController::class, 'storeGroup'])->name('classrooms.store');
    Route::post('/buildings',           [SchoolController::class, 'storeBuilding'])->name('buildings.store');
});

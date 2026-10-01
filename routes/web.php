<?php

use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DeviceController;
use App\Http\Controllers\Web\MemberWebController;
use App\Http\Controllers\Web\OrganizationController;
use App\Http\Controllers\Web\ReportController;
use Illuminate\Support\Facades\Route;

// Dashboard
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Members — full CRUD + enrollment
Route::post('members/import', [MemberWebController::class, 'import'])->name('members.import');
Route::resource('members', MemberWebController::class);
Route::post('members/{member}/enroll', [MemberWebController::class, 'enrollStore'])->name('members.enroll.store');

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
});

// Organizations, Groups, Branches
Route::prefix('organizations')->name('organizations.')->group(function () {
    Route::get('/',                    [OrganizationController::class, 'index'])->name('index');
    Route::post('/',                   [OrganizationController::class, 'store'])->name('store');
    Route::post('/groups',             [OrganizationController::class, 'storeGroup'])->name('groups.store');
    Route::post('/branches',           [OrganizationController::class, 'storeBranch'])->name('branches.store');
});

<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::resource('users', \App\Http\Controllers\Web\User\UserController::class)
    ->only(['index', 'store']);

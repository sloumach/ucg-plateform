<?php

use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:login')
    ->post('/auth/login', [AuthController::class, 'login'])
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'current'])
        ->name('auth.current');
    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->name('auth.logout');
});

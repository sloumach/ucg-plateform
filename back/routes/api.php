<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SystemStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/system/status', SystemStatusController::class)
        ->name('system.status');

    Route::middleware('throttle:login')->group(function (): void {
        Route::post('/auth/login', [AuthController::class, 'login'])
            ->name('auth.login');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'current'])
            ->name('auth.current');
        Route::post('/auth/logout', [AuthController::class, 'logout'])
            ->name('auth.logout');
    });
});

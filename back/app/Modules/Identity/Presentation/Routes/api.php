<?php

use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\AuthController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\EmailVerificationController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\PasswordRecoveryController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\SessionController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:login')
    ->post('/auth/login', [AuthController::class, 'login'])
    ->name('auth.login');

Route::middleware('throttle:password-recovery')->group(function (): void {
    Route::post('/auth/forgot-password', [PasswordRecoveryController::class, 'requestLink'])
        ->name('password.email');
    Route::post('/auth/reset-password', [PasswordRecoveryController::class, 'reset'])
        ->name('password.update');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'current'])
        ->name('auth.current');
    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->name('auth.logout');
    Route::post('/auth/sessions/revoke-others', [SessionController::class, 'revokeOthers'])
        ->name('auth.sessions.revoke-others');
    Route::post('/auth/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:verification')
        ->name('verification.send');
    Route::get('/auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('verification.verify');
});

<?php

use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\AuthController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\EmailVerificationController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\MfaController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\PasswordRecoveryController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\PlatformAuditController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/platform/audit-events', [PlatformAuditController::class, 'index'])
    ->middleware(['auth:sanctum', 'identity.session', 'audit.failures', 'mfa', 'throttle:audit'])->block(10, 10)->name('platform.audit.index');

Route::middleware('throttle:login')
    ->post('/auth/login', [AuthController::class, 'login'])
    ->name('auth.login');

Route::middleware('throttle:password-recovery')->group(function (): void {
    Route::post('/auth/forgot-password', [PasswordRecoveryController::class, 'requestLink'])
        ->name('password.email');
    Route::post('/auth/reset-password', [PasswordRecoveryController::class, 'reset'])
        ->name('password.update');
});

Route::middleware(['auth:sanctum', 'identity.session'])->group(function (): void {
    Route::get('/auth/mfa', [MfaController::class, 'show'])->name('mfa.show');
    Route::middleware('throttle:mfa')->group(function (): void {
        Route::post('/auth/mfa/enrollment', [MfaController::class, 'begin'])->block(10, 10)->name('mfa.begin');
        Route::post('/auth/mfa/confirmation', [MfaController::class, 'confirm'])->block(10, 10)->name('mfa.confirm');
        Route::post('/auth/mfa/challenge', [MfaController::class, 'challenge'])->block(10, 10)->name('mfa.challenge');
        Route::post('/auth/mfa/recovery-codes', [MfaController::class, 'regenerate'])->middleware('mfa')->block(10, 10)->name('mfa.regenerate');
        Route::delete('/auth/mfa', [MfaController::class, 'disable'])->middleware('mfa')->block(10, 10)->name('mfa.disable');
    });
    Route::get('/auth/me', [AuthController::class, 'current'])
        ->name('auth.current');
    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->name('auth.logout');
    Route::post('/auth/sessions/revoke-others', [SessionController::class, 'revokeOthers'])
        ->middleware('mfa')
        ->name('auth.sessions.revoke-others');
    Route::post('/auth/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:verification')
        ->name('verification.send');
    Route::get('/auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('verification.verify');
});

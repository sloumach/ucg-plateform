<?php

use App\Modules\Academy\Presentation\Http\Controllers\Api\V1\AcademySessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/academy/cohorts/{cohort}/cycles/{cycle}')->whereUuid(['tenant', 'cohort', 'cycle'])->middleware('tenant')->group(function (): void {
    $controller = AcademySessionController::class;
    Route::get('session-activities', [$controller, 'activities'])->defaults('session_kind', 'activities')->name('academy.session-activities');
    Route::get('sessions', [$controller, 'index'])->defaults('session_kind', 'session')->name('academy.sessions.index');
    Route::post('sessions', [$controller, 'save'])->defaults('session_kind', 'session')->middleware('tenant.confirmed')->block(10, 10)->name('academy.sessions.store');
    Route::patch('sessions/{session}', [$controller, 'save'])->whereUuid('session')->defaults('session_kind', 'session')->middleware('tenant.confirmed')->block(10, 10)->name('academy.sessions.update');
    Route::get('sessions/{session}/attendances', [$controller, 'index'])->whereUuid('session')->defaults('session_kind', 'attendance')->name('academy.attendances.index');
    Route::post('sessions/{session}/attendances', [$controller, 'save'])->whereUuid('session')->defaults('session_kind', 'attendance')->middleware('tenant.confirmed')->block(10, 10)->name('academy.attendances.store');
    Route::patch('sessions/{session}/attendances/{attendance}', [$controller, 'save'])->whereUuid(['session', 'attendance'])->defaults('session_kind', 'attendance')->middleware('tenant.confirmed')->block(10, 10)->name('academy.attendances.update');
    Route::get('sessions/{session}/history', [$controller, 'index'])->whereUuid('session')->defaults('session_kind', 'history')->name('academy.sessions.history');
});

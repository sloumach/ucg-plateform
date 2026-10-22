<?php

use App\Modules\Academy\Presentation\Http\Controllers\Api\V1\AcademyCoachingController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/academy')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    $controller = AcademyCoachingController::class;
    Route::get('my-objectives', [$controller, 'index'])->defaults('coaching_kind', 'self')->name('academy.my-objectives');
    Route::get('my-objectives/{objective}/feedback', [$controller, 'index'])->whereUuid('objective')->defaults('coaching_kind', 'self-feedback')->name('academy.my-feedback');
    Route::prefix('cohorts/{cohort}/cycles/{cycle}/objectives')->whereUuid(['cohort', 'cycle'])->group(function () use ($controller): void {
        Route::get('', [$controller, 'index'])->defaults('coaching_kind', 'objective')->name('academy.objectives.index');
        Route::post('', [$controller, 'save'])->defaults('coaching_kind', 'objective')->middleware('tenant.confirmed')->block(10, 10)->name('academy.objectives.store');
        Route::patch('{objective}', [$controller, 'save'])->whereUuid('objective')->defaults('coaching_kind', 'objective')->middleware('tenant.confirmed')->block(10, 10)->name('academy.objectives.update');
        foreach (['feedback' => 'shared', 'notes' => 'internal'] as $path => $kind) {
            Route::get('{objective}/'.$path, [$controller, 'index'])->whereUuid('objective')->defaults('coaching_kind', $kind)->name('academy.'.$path.'.index');
            Route::post('{objective}/'.$path, [$controller, 'save'])->whereUuid('objective')->defaults('coaching_kind', $kind)->middleware('tenant.confirmed')->block(10, 10)->name('academy.'.$path.'.store');
            Route::patch('{objective}/'.$path.'/{feedback}', [$controller, 'save'])->whereUuid(['objective', 'feedback'])->defaults('coaching_kind', $kind)->middleware('tenant.confirmed')->block(10, 10)->name('academy.'.$path.'.update');
        }
        Route::get('{objective}/history', [$controller, 'index'])->whereUuid('objective')->defaults('coaching_kind', 'history')->name('academy.coaching.history');
    });
});

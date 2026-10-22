<?php

use App\Modules\Academy\Presentation\Http\Controllers\Api\V1\AcademyController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/academy')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    $controller = AcademyController::class;
    Route::get('people', [$controller, 'choices'])->defaults('academy_kind', 'choices')->name('academy.people');
    Route::get('cohorts', [$controller, 'index'])->defaults('academy_kind', 'cohort')->name('academy.cohorts.index');
    Route::post('cohorts', [$controller, 'store'])->defaults('academy_kind', 'cohort')->middleware('tenant.confirmed')->block(10, 10)->name('academy.cohorts.store');
    Route::patch('cohorts/{resource}', [$controller, 'update'])->whereUuid('resource')->defaults('academy_kind', 'cohort')
        ->middleware('tenant.confirmed')->block(10, 10)->name('academy.cohorts.update');
    foreach (['cycles' => 'cycle', 'enrollments' => 'enrollment'] as $path => $kind) {
        Route::get('cohorts/{cohort}/'.$path, [$controller, 'index'])->whereUuid('cohort')->defaults('academy_kind', $kind)->name('academy.'.$path.'.index');
        Route::post('cohorts/{cohort}/'.$path, [$controller, 'store'])->whereUuid('cohort')->defaults('academy_kind', $kind)
            ->middleware('tenant.confirmed')->block(10, 10)->name('academy.'.$path.'.store');
        Route::patch('cohorts/{cohort}/'.$path.'/{resource}', [$controller, 'update'])->whereUuid(['cohort', 'resource'])->defaults('academy_kind', $kind)
            ->middleware('tenant.confirmed')->block(10, 10)->name('academy.'.$path.'.update');
    }
    Route::get('cohorts/{cohort}/history', [$controller, 'index'])->whereUuid('cohort')->defaults('academy_kind', 'history')->name('academy.history');
});

require __DIR__.'/sessions.php';
require __DIR__.'/coaching.php';

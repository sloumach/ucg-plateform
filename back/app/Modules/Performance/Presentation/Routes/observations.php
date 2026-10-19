<?php

use App\Modules\Performance\Presentation\Http\Controllers\Api\V1\ObservationController as Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/observations')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('', [Controller::class, 'index'])->name('observations.index');
    Route::post('', [Controller::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('observations.store');
    Route::get('choices', [Controller::class, 'choices'])->name('observations.choices');
    Route::get('{observation}', [Controller::class, 'show'])->whereUuid('observation')->name('observations.show');
    Route::patch('{observation}', [Controller::class, 'update'])->whereUuid('observation')->middleware('tenant.confirmed')->block(10, 10)->name('observations.update');
    Route::get('{observation}/history', [Controller::class, 'history'])->whereUuid('observation')->name('observations.history');
    Route::get('{observation}/versions/{version}', [Controller::class, 'version'])->whereUuid(['observation', 'version'])->name('observations.version');
});

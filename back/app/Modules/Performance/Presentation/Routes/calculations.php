<?php

use App\Modules\Performance\Presentation\Http\Controllers\Api\V1\StatisticalCalculationController as Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/statistical-calculations')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('', [Controller::class, 'index'])->name('statistical-calculations.index');
    Route::post('', [Controller::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('statistical-calculations.store');
    Route::get('{calculation}', [Controller::class, 'show'])->whereUuid('calculation')->name('statistical-calculations.show');
    Route::get('{calculation}/history', [Controller::class, 'history'])->whereUuid('calculation')->name('statistical-calculations.history');
    Route::post('{calculation}/recalculate', [Controller::class, 'recalculate'])->whereUuid('calculation')->middleware('tenant.confirmed')->block(10, 10)->name('statistical-calculations.recalculate');
});

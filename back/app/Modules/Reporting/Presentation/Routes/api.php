<?php

use App\Modules\Reporting\Presentation\Http\Controllers\Api\V1\DashboardController as Controller;
use App\Modules\Reporting\Presentation\Http\Controllers\Api\V1\DashboardIndicatorController;
use App\Modules\Reporting\Presentation\Http\Controllers\Api\V1\TenantSearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/dashboards')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('{card}/indicator', [DashboardIndicatorController::class, 'show'])->where('card', '[a-z_]+')->name('dashboards.indicator.show');
    Route::post('{card}/indicator', [DashboardIndicatorController::class, 'store'])->where('card', '[a-z_]+')->middleware('tenant.confirmed')->name('dashboards.indicator.store');
    Route::get('', [Controller::class, 'index'])->name('dashboards.index');
    Route::get('{card}', [Controller::class, 'show'])->where('card', '[a-z_]+')->name('dashboards.show');
});

Route::prefix('tenants/{tenant}/search')->whereUuid('tenant')->middleware(['tenant', 'throttle:tenant-search'])->group(function (): void {
    Route::get('types', [TenantSearchController::class, 'types'])->name('search.types');
    Route::get('', [TenantSearchController::class, 'index'])->name('search.index');
});

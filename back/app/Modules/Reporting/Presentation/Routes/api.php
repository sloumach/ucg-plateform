<?php

use App\Modules\Reporting\Presentation\Http\Controllers\Api\V1\DashboardController as Controller;
use App\Modules\Reporting\Presentation\Http\Controllers\Api\V1\DashboardIndicatorController;
use App\Modules\Reporting\Presentation\Http\Controllers\Api\V1\ReportExportController;
use App\Modules\Reporting\Presentation\Http\Controllers\Api\V1\TenantSearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/exports')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('', [ReportExportController::class, 'index'])->name('exports.index');
    Route::post('', [ReportExportController::class, 'store'])->middleware(['tenant.confirmed', 'throttle:report-exports'])->name('exports.store');
    Route::get('{export}', [ReportExportController::class, 'show'])->whereUuid('export')->name('exports.show');
    Route::get('{export}/download', [ReportExportController::class, 'download'])->whereUuid('export')->middleware('throttle:report-exports')->name('exports.download');
});

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

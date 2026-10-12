<?php

use App\Modules\People\Presentation\Http\Controllers\Api\V1\ExternalGameAccountController;
use App\Modules\People\Presentation\Http\Controllers\Api\V1\GameProfileCatalogueController;
use App\Modules\People\Presentation\Http\Controllers\Api\V1\GameProfileController;
use App\Modules\People\Presentation\Http\Controllers\Api\V1\PersonController;
use App\Modules\People\Presentation\Http\Controllers\Api\V1\PersonDepartureController;
use App\Modules\People\Presentation\Http\Controllers\Api\V1\PersonDepartureItemController;
use App\Modules\People\Presentation\Http\Controllers\Api\V1\PersonImportController;
use App\Modules\People\Presentation\Http\Controllers\Api\V1\PersonPeriodController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/people-imports')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    $controller = PersonImportController::class;
    Route::get('/', [$controller, 'index'])->name('people-imports.index');
    Route::post('/', [$controller, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('people-imports.store');
    Route::get('{import}', [$controller, 'show'])->whereUuid('import')->name('people-imports.show');
    Route::get('{import}/report', [$controller, 'report'])->whereUuid('import')->name('people-imports.report');
    Route::post('{import}/preview', [$controller, 'preview'])->whereUuid('import')->middleware('tenant.confirmed')->block(10, 10)->name('people-imports.preview');
    Route::post('{import}/confirm', [$controller, 'confirm'])->whereUuid('import')->middleware('tenant.confirmed')->block(10, 10)->name('people-imports.confirm');
});

Route::get('tenants/{tenant}/game-profile-catalogue', [GameProfileCatalogueController::class, 'index'])
    ->whereUuid('tenant')->middleware('tenant')->name('game-profiles.catalogue');

Route::prefix('tenants/{tenant}/people/{person}/game-profiles')->whereUuid(['tenant', 'person'])->middleware('tenant')->group(function (): void {
    Route::get('/', [GameProfileController::class, 'index'])->name('game-profiles.index');
    Route::post('/', [GameProfileController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('game-profiles.store');
    Route::get('{profile}', [GameProfileController::class, 'show'])->whereUuid('profile')->name('game-profiles.show');
    Route::patch('{profile}', [GameProfileController::class, 'update'])->whereUuid('profile')
        ->middleware('tenant.confirmed')->block(10, 10)->name('game-profiles.update');
    Route::prefix('{profile}/external-accounts')->whereUuid('profile')->group(function (): void {
        Route::get('/', [ExternalGameAccountController::class, 'index'])->name('external-accounts.index');
        Route::post('/', [ExternalGameAccountController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('external-accounts.store');
        Route::get('{account}', [ExternalGameAccountController::class, 'show'])->whereUuid('account')->name('external-accounts.show');
        Route::patch('{account}', [ExternalGameAccountController::class, 'update'])->whereUuid('account')
            ->middleware('tenant.confirmed')->block(10, 10)->name('external-accounts.update');
    });
});

Route::prefix('tenants/{tenant}/people')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::prefix('{person}')->whereUuid('person')->group(function (): void {
        Route::get('periods', [PersonPeriodController::class, 'index'])->name('people.periods.index');
        Route::post('periods', [PersonPeriodController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('people.periods.store');
        Route::patch('periods/{record}', [PersonPeriodController::class, 'update'])->whereUuid('record')->middleware('tenant.confirmed')->block(10, 10)->name('people.periods.update');
        Route::get('departure-items', [PersonDepartureItemController::class, 'index'])->name('people.departure-items.index');
        Route::post('departure-items', [PersonDepartureItemController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('people.departure-items.store');
        Route::patch('departure-items/{record}', [PersonDepartureItemController::class, 'update'])->whereUuid('record')->middleware('tenant.confirmed')->block(10, 10)->name('people.departure-items.update');
        Route::get('departure', [PersonDepartureController::class, 'show'])->name('people.departure.show');
        Route::post('departure', [PersonDepartureController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('people.departure.store');
    });
    Route::get('/', [PersonController::class, 'index'])->name('people.index');
    Route::get('{person}', [PersonController::class, 'show'])->whereUuid('person')->block(10, 10)->name('people.show');
    Route::post('/', [PersonController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('people.store');
    Route::patch('{person}', [PersonController::class, 'update'])->whereUuid('person')->middleware('tenant.confirmed')->block(10, 10)->name('people.update');
});

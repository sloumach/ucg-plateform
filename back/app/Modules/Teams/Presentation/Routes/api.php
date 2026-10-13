<?php

use App\Modules\Teams\Presentation\Http\Controllers\Api\V1\GameCatalogueController;
use App\Modules\Teams\Presentation\Http\Controllers\Api\V1\OrganizationGameController;
use Illuminate\Support\Facades\Route;

Route::prefix('game-catalogue')->group(function (): void {
    Route::get('/', [GameCatalogueController::class, 'index'])->name('game-catalogue.index');
    Route::post('/', [GameCatalogueController::class, 'store'])->block(10, 10)->name('game-catalogue.store');
    Route::get('{entry}', [GameCatalogueController::class, 'show'])->whereUuid('entry')->name('game-catalogue.show');
    Route::patch('{entry}', [GameCatalogueController::class, 'update'])->whereUuid('entry')->block(10, 10)->name('game-catalogue.update');
});
Route::prefix('tenants/{tenant}/games')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('/', [OrganizationGameController::class, 'index'])->name('organization-games.index');
    Route::get('{game}', [OrganizationGameController::class, 'show'])->whereUuid('game')->name('organization-games.show');
    Route::patch('{game}', [OrganizationGameController::class, 'update'])->whereUuid('game')
        ->middleware('tenant.confirmed')->block(10, 10)->name('organization-games.update');
});

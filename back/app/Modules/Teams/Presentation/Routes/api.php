<?php

use App\Modules\Teams\Presentation\Http\Controllers\Api\V1\GameCatalogueController;
use App\Modules\Teams\Presentation\Http\Controllers\Api\V1\OrganizationGameController;
use App\Modules\Teams\Presentation\Http\Controllers\Api\V1\TeamController;
use App\Modules\Teams\Presentation\Http\Controllers\Api\V1\TeamMembershipController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/teams')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    $memberships = TeamMembershipController::class;
    Route::get('{team}/memberships', [$memberships, 'index'])->whereUuid('team')->name('team-memberships.index');
    Route::get('{team}/movements', [$memberships, 'movements'])->whereUuid('team')->name('team-memberships.movements');
    Route::post('{team}/memberships', [$memberships, 'store'])->whereUuid('team')->middleware('tenant.confirmed')->block(10, 10)->name('team-memberships.store');
    Route::patch('{team}/memberships/{membership}', [$memberships, 'update'])->whereUuid(['team', 'membership'])->middleware('tenant.confirmed')->block(10, 10)->name('team-memberships.update');
    $controller = TeamController::class;
    Route::get('/', [$controller, 'index'])->name('teams.index');
    Route::get('{team}', [$controller, 'show'])->whereUuid('team')->name('teams.show');
    Route::post('/', [$controller, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('teams.store');
    Route::patch('{team}', [$controller, 'update'])->whereUuid('team')->middleware('tenant.confirmed')->block(10, 10)->name('teams.update');
});

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

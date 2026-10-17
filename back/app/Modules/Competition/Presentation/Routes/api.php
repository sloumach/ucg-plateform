<?php

use App\Modules\Competition\Presentation\Http\Controllers\Api\V1\MatchController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/matches')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('', [MatchController::class, 'index'])->name('matches.index');
    Route::get('references/{type}', [MatchController::class, 'references'])->whereIn('type', ['game', 'person', 'team', 'opponent_snapshot'])->name('matches.references');
    Route::post('', [MatchController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('matches.store');
    Route::get('{match}', [MatchController::class, 'show'])->whereUuid('match')->name('matches.show');
    Route::get('{match}/history', [MatchController::class, 'history'])->whereUuid('match')->name('matches.history');
    Route::patch('{match}', [MatchController::class, 'update'])->whereUuid('match')->middleware('tenant.confirmed')->block(10, 10)->name('matches.update');
    Route::post('{match}/transitions', [MatchController::class, 'transition'])->whereUuid('match')->middleware('tenant.confirmed')->block(10, 10)->name('matches.transitions');
});

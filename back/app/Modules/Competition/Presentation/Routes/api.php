<?php

use App\Modules\Competition\Presentation\Http\Controllers\Api\V1\MatchController;
use App\Modules\Competition\Presentation\Http\Controllers\Api\V1\MatchLineupController;
use App\Modules\Competition\Presentation\Http\Controllers\Api\V1\MatchResultController;
use App\Modules\Competition\Presentation\Http\Controllers\Api\V1\MatchScoreController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/matches')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::prefix('{match}/participants/{participant}/lineups')->whereUuid(['match', 'participant'])->group(function (): void {
        Route::get('', [MatchLineupController::class, 'index'])->name('matches.lineups.index');
        Route::get('choices', [MatchLineupController::class, 'choices'])->name('matches.lineups.choices');
        Route::post('', [MatchLineupController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('matches.lineups.store');
        Route::post('substitutions', [MatchLineupController::class, 'substitute'])->middleware('tenant.confirmed')->block(10, 10)->name('matches.lineups.substitute');
    });
    Route::get('{match}/results', [MatchResultController::class, 'index'])->whereUuid('match')->name('matches.results.index');
    Route::post('{match}/results', [MatchResultController::class, 'submit'])->whereUuid('match')->middleware('tenant.confirmed')->block(10, 10)->name('matches.results.submit');
    Route::post('{match}/results/approvals', [MatchResultController::class, 'approve'])->whereUuid('match')->middleware('tenant.confirmed')->block(10, 10)->name('matches.results.approve');
    Route::post('{match}/results/disputes', [MatchResultController::class, 'dispute'])->whereUuid('match')->middleware('tenant.confirmed')->block(10, 10)->name('matches.results.dispute');
    Route::get('{match}/games', [MatchScoreController::class, 'index'])->whereUuid('match')->name('matches.games.index');
    Route::post('{match}/games', [MatchScoreController::class, 'store'])->whereUuid('match')->middleware('tenant.confirmed')->block(10, 10)->name('matches.games.store');
    Route::post('{match}/score-rules', [MatchScoreController::class, 'configure'])->whereUuid('match')->middleware('tenant.confirmed')->block(10, 10)->name('matches.score-rules.configure');
    Route::get('', [MatchController::class, 'index'])->name('matches.index');
    Route::get('references/{type}', [MatchController::class, 'references'])->whereIn('type', ['game', 'person', 'team', 'opponent_snapshot'])->name('matches.references');
    Route::post('', [MatchController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('matches.store');
    Route::get('{match}', [MatchController::class, 'show'])->whereUuid('match')->name('matches.show');
    Route::get('{match}/history', [MatchController::class, 'history'])->whereUuid('match')->name('matches.history');
    Route::patch('{match}', [MatchController::class, 'update'])->whereUuid('match')->middleware('tenant.confirmed')->block(10, 10)->name('matches.update');
    Route::post('{match}/transitions', [MatchController::class, 'transition'])->whereUuid('match')->middleware('tenant.confirmed')->block(10, 10)->name('matches.transitions');
});

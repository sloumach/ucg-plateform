<?php

use App\Modules\Competition\Presentation\Http\Controllers\Api\V1\ExternalCompetitionController;
use App\Modules\Competition\Presentation\Http\Controllers\Api\V1\MatchController;
use App\Modules\Competition\Presentation\Http\Controllers\Api\V1\MatchCorrectionController;
use App\Modules\Competition\Presentation\Http\Controllers\Api\V1\MatchLineupController;
use App\Modules\Competition\Presentation\Http\Controllers\Api\V1\MatchNotebookController;
use App\Modules\Competition\Presentation\Http\Controllers\Api\V1\MatchRealtimeController;
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
    Route::prefix('{match}/notebook')->whereUuid('match')->group(function (): void {
        Route::get('', [MatchNotebookController::class, 'index'])->name('matches.notebook.index');
        Route::get('{entry}/history', [MatchNotebookController::class, 'history'])->whereUuid('entry')->name('matches.notebook.history');
        Route::post('', [MatchNotebookController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('matches.notebook.store');
        Route::patch('{entry}', [MatchNotebookController::class, 'update'])->whereUuid('entry')->middleware('tenant.confirmed')->block(10, 10)->name('matches.notebook.update');
    });
    Route::get('{match}/live', [MatchRealtimeController::class, 'show'])->whereUuid('match')->name('matches.live');
    Route::post('{match}/realtime/auth', MatchRealtimeController::class)->whereUuid('match')->middleware('tenant.confirmed')->name('matches.realtime.auth');
    Route::get('{match}/corrections', [MatchCorrectionController::class, 'index'])->whereUuid('match')->name('matches.corrections.index');
    Route::post('{match}/corrections', [MatchCorrectionController::class, 'store'])->whereUuid('match')->middleware('tenant.confirmed')->block(10, 10)->name('matches.corrections.store');
    Route::post('{match}/corrections/{correction}/retries', [MatchCorrectionController::class, 'retry'])->whereUuid(['match', 'correction'])->middleware('tenant.confirmed')->block(10, 10)->name('matches.corrections.retry');
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

Route::prefix('tenants/{tenant}/external-competitions')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    $controller = ExternalCompetitionController::class;
    Route::get('', $controller.'@index')->name('external-competitions.index');
    Route::get('games', $controller.'@games')->name('external-competitions.games');
    Route::post('', $controller.'@store')->middleware('tenant.confirmed')->block(10, 10)->name('external-competitions.store');
    Route::prefix('{competition}')->whereUuid('competition')->group(function () use ($controller): void {
        Route::get('', $controller.'@show')->name('external-competitions.show');
        Route::patch('', $controller.'@update')->middleware('tenant.confirmed')->block(10, 10)->name('external-competitions.update');
        Route::get('history', $controller.'@history')->name('external-competitions.history');
        Route::get('documents', $controller.'@documents')->name('external-competitions.documents');
        Route::post('documents', $controller.'@documentStore')->middleware('tenant.confirmed')->block(10, 10)->name('external-competitions.documents.store');
        Route::get('documents/{document}/history', $controller.'@documentHistory')->whereUuid('document')->name('external-competitions.documents.history');
        Route::patch('documents/{document}', $controller.'@documentUpdate')->whereUuid('document')->middleware('tenant.confirmed')->block(10, 10)->name('external-competitions.documents.update');
        Route::get('documents/{document}/versions/{version}/download', $controller.'@download')->whereUuid(['document', 'version'])->name('external-competitions.documents.download');
    });
});

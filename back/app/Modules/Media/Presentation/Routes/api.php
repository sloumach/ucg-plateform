<?php

use App\Modules\Media\Presentation\Http\Controllers\Api\V1\CommunicationController;
use App\Modules\Media\Presentation\Http\Controllers\Api\V1\MediaController;
use App\Modules\Media\Presentation\Http\Controllers\Api\V1\WorkTaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/tasks')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('/', [WorkTaskController::class, 'index'])->name('tasks.index');
    Route::get('people', [WorkTaskController::class, 'people'])->name('tasks.people');
    Route::get('{task}', [WorkTaskController::class, 'show'])->whereUuid('task')->name('tasks.show');
    Route::post('/', [WorkTaskController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('tasks.store');
    Route::patch('{task}', [WorkTaskController::class, 'update'])->whereUuid('task')->middleware('tenant.confirmed')->block(10, 10)->name('tasks.update');
});

Route::prefix('tenants/{tenant}/media')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('/', [MediaController::class, 'index'])->name('media.index');
    Route::get('teams', [MediaController::class, 'teams'])->name('media.teams');
    Route::get('{media}', [MediaController::class, 'show'])->whereUuid('media')->name('media.show');
    Route::post('/', [MediaController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('media.store');
    Route::patch('{media}', [MediaController::class, 'update'])->whereUuid('media')->middleware('tenant.confirmed')->block(10, 10)->name('media.update');
});

Route::prefix('tenants/{tenant}/communication')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    $controller = CommunicationController::class;
    Route::get('announcements', [$controller, 'index'])->name('communication.index');
    Route::get('targets', [$controller, 'targets'])->name('communication.targets');
    Route::get('context-targets', [$controller, 'contextTargets'])->name('communication.contextTargets');
    Route::get('discussions/{type}/{contextId}', [$controller, 'discussion'])->whereIn('type', ['event', 'match', 'session', 'task'])->whereUuid('contextId')->name('communication.discussion');
    Route::get('preferences', [$controller, 'preferences'])->name('communication.preferences');
    Route::get('inbox', [$controller, 'inbox'])->name('communication.inbox');
    Route::get('announcements/{announcement}', [$controller, 'show'])->whereUuid('announcement')->name('communication.show');
    Route::get('announcements/{announcement}/comments', [$controller, 'comments'])->whereUuid('announcement')->name('communication.comments');
    Route::middleware('tenant.confirmed')->group(function () use ($controller): void {
        Route::post('discussions/{type}/{contextId}', [$controller, 'discuss'])->whereIn('type', ['event', 'match', 'session', 'task'])->whereUuid('contextId')->block(10, 10)->name('communication.discuss');
        Route::post('announcements', [$controller, 'store'])->block(10, 10)->name('communication.store');
        Route::patch('announcements/{announcement}', [$controller, 'update'])->whereUuid('announcement')->block(10, 10)->name('communication.update');
        Route::post('announcements/{announcement}/comments', [$controller, 'comment'])->whereUuid('announcement')->block(10, 10)->name('communication.comment');
        Route::patch('preferences', [$controller, 'savePreferences'])->block(10, 10)->name('communication.savePreferences');
        Route::post('inbox/{event}/read', [$controller, 'markRead'])->whereUuid('event')->block(10, 10)->name('communication.markRead');
    });
});

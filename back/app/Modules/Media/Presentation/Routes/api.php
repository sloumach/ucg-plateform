<?php

use App\Modules\Media\Presentation\Http\Controllers\Api\V1\CommunicationController;
use App\Modules\Media\Presentation\Http\Controllers\Api\V1\MediaController;
use Illuminate\Support\Facades\Route;

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
    Route::get('preferences', [$controller, 'preferences'])->name('communication.preferences');
    Route::get('inbox', [$controller, 'inbox'])->name('communication.inbox');
    Route::get('announcements/{announcement}', [$controller, 'show'])->whereUuid('announcement')->name('communication.show');
    Route::get('announcements/{announcement}/comments', [$controller, 'comments'])->whereUuid('announcement')->name('communication.comments');
    Route::middleware('tenant.confirmed')->group(function () use ($controller): void {
        Route::post('announcements', [$controller, 'store'])->block(10, 10)->name('communication.store');
        Route::patch('announcements/{announcement}', [$controller, 'update'])->whereUuid('announcement')->block(10, 10)->name('communication.update');
        Route::post('announcements/{announcement}/comments', [$controller, 'comment'])->whereUuid('announcement')->block(10, 10)->name('communication.comment');
        Route::patch('preferences', [$controller, 'savePreferences'])->block(10, 10)->name('communication.savePreferences');
        Route::post('inbox/{event}/read', [$controller, 'markRead'])->whereUuid('event')->block(10, 10)->name('communication.markRead');
    });
});

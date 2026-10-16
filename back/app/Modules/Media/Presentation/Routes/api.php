<?php

use App\Modules\Media\Presentation\Http\Controllers\Api\V1\MediaController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/media')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('/', [MediaController::class, 'index'])->name('media.index');
    Route::get('teams', [MediaController::class, 'teams'])->name('media.teams');
    Route::get('{media}', [MediaController::class, 'show'])->whereUuid('media')->name('media.show');
    Route::post('/', [MediaController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('media.store');
    Route::patch('{media}', [MediaController::class, 'update'])->whereUuid('media')->middleware('tenant.confirmed')->block(10, 10)->name('media.update');
});

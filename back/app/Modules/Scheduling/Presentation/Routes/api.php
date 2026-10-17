<?php

use App\Modules\Scheduling\Presentation\Http\Controllers\Api\V1\CalendarController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/calendar')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('activities', [CalendarController::class, 'index'])->name('calendar.index');
    Route::get('teams', [CalendarController::class, 'teams'])->name('calendar.teams');
    Route::get('people', [CalendarController::class, 'people'])->name('calendar.people');
    Route::get('activities/{activity}', [CalendarController::class, 'show'])->whereUuid('activity')->name('calendar.show');
    Route::post('activities', [CalendarController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('calendar.store');
    Route::patch('activities/{activity}', [CalendarController::class, 'update'])->whereUuid('activity')->middleware('tenant.confirmed')->block(10, 10)->name('calendar.update');
});

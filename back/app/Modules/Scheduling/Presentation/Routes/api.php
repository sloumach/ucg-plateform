<?php

use App\Modules\Scheduling\Presentation\Http\Controllers\Api\V1\AvailabilityController;
use App\Modules\Scheduling\Presentation\Http\Controllers\Api\V1\CalendarController;
use App\Modules\Scheduling\Presentation\Http\Controllers\Api\V1\CalendarFeedController;
use App\Modules\Scheduling\Presentation\Http\Controllers\Api\V1\CalendarInvitationController;
use App\Modules\Scheduling\Presentation\Http\Controllers\Api\V1\CalendarResourceController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/calendar')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('feed', [CalendarFeedController::class, 'show'])->name('calendar.feed.show');
    Route::post('feed', [CalendarFeedController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('calendar.feed.store');
    Route::patch('feed', [CalendarFeedController::class, 'revoke'])->middleware('tenant.confirmed')->block(10, 10)->name('calendar.feed.revoke');
    $invitations = CalendarInvitationController::class;
    Route::get('invitations', [$invitations, 'index'])->name('calendar.invitations.index');
    Route::get('activities/{activity}/invitations', [$invitations, 'activityIndex'])->whereUuid('activity')->name('calendar.invitations.activity');
    Route::post('activities/{activity}/invitations', [$invitations, 'issue'])->whereUuid('activity')->middleware('tenant.confirmed')->block(10, 10)->name('calendar.invitations.issue');
    Route::patch('invitations/{invitation}', [$invitations, 'respond'])->whereUuid('invitation')->middleware('tenant.confirmed')->block(10, 10)->name('calendar.invitations.respond');
    Route::get('invitations/{invitation}/deliveries', [$invitations, 'history'])->whereUuid('invitation')->name('calendar.invitations.history');
    $resources = CalendarResourceController::class;
    Route::get('resources', [$resources, 'index'])->name('calendar.resources.index');
    Route::post('resources', [$resources, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('calendar.resources.store');
    Route::patch('resources/{resource}', [$resources, 'update'])->whereUuid('resource')->middleware('tenant.confirmed')->block(10, 10)->name('calendar.resources.update');
    Route::post('activity-conflicts', [CalendarController::class, 'conflicts'])->name('calendar.conflicts');
    Route::get('activities/{activity}/conflict-overrides', [CalendarController::class, 'conflictOverrides'])->whereUuid('activity')->name('calendar.conflict-overrides');
    $availability = AvailabilityController::class;
    Route::get('availabilities', [$availability, 'index'])->name('availability.index');
    Route::get('availability-people', [$availability, 'people'])->name('availability.people');
    Route::get('availabilities/{availability}', [$availability, 'show'])->whereUuid('availability')->name('availability.show');
    Route::get('availabilities/{availability}/history', [$availability, 'history'])->whereUuid('availability')->name('availability.history');
    Route::post('availabilities', [$availability, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('availability.store');
    Route::patch('availabilities/{availability}', [$availability, 'update'])->whereUuid('availability')->middleware('tenant.confirmed')->block(10, 10)->name('availability.update');
    Route::get('activities', [CalendarController::class, 'index'])->name('calendar.index');
    Route::get('teams', [CalendarController::class, 'teams'])->name('calendar.teams');
    Route::get('people', [CalendarController::class, 'people'])->name('calendar.people');
    Route::get('activities/{activity}', [CalendarController::class, 'show'])->whereUuid('activity')->name('calendar.show');
    Route::post('activities', [CalendarController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('calendar.store');
    Route::patch('activities/{activity}', [CalendarController::class, 'update'])->whereUuid('activity')->middleware('tenant.confirmed')->block(10, 10)->name('calendar.update');
});

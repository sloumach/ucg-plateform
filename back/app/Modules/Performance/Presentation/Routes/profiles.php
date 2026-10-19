<?php

use App\Modules\Performance\Presentation\Http\Controllers\Api\V1\StatisticalProfileController as Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/statistical-profiles')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('people', [Controller::class, 'people'])->name('statistical-profiles.people');
    Route::get('', [Controller::class, 'show'])->name('statistical-profiles.show');
});

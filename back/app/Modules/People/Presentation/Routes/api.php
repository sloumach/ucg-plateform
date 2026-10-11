<?php

use App\Modules\People\Presentation\Http\Controllers\Api\V1\PersonController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/people')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('{person}', [PersonController::class, 'show'])->whereUuid('person')->block(10, 10)->name('people.show');
    Route::post('/', [PersonController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('people.store');
    Route::patch('{person}', [PersonController::class, 'update'])->whereUuid('person')->middleware('tenant.confirmed')->block(10, 10)->name('people.update');
});

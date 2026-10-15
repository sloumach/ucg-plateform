<?php

use App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1\SponsorController;
use App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1\SponsorRelationshipController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/sponsors')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('/', [SponsorController::class, 'index'])->name('sponsors.index');
    Route::get('{sponsor}', [SponsorController::class, 'show'])->whereUuid('sponsor')->name('sponsors.show');
    Route::post('/', [SponsorController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('sponsors.store');
    Route::patch('{sponsor}', [SponsorController::class, 'update'])->whereUuid('sponsor')->middleware('tenant.confirmed')->block(10, 10)->name('sponsors.update');
    Route::get('{sponsor}/{kind}', [SponsorRelationshipController::class, 'index'])->whereUuid('sponsor')
        ->where('kind', 'contacts|opportunities|interactions')->name('sponsor-related.index');
    Route::post('{sponsor}/{kind}', [SponsorRelationshipController::class, 'store'])->whereUuid('sponsor')
        ->where('kind', 'contacts|opportunities|interactions')->middleware('tenant.confirmed')->block(10, 10)->name('sponsor-related.store');
    Route::patch('{sponsor}/{kind}/{record}', [SponsorRelationshipController::class, 'update'])->whereUuid(['sponsor', 'record'])
        ->where('kind', 'contacts|opportunities')->middleware('tenant.confirmed')->block(10, 10)->name('sponsor-related.update');
});

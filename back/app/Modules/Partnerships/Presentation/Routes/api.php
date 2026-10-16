<?php

use App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1\SponsorController;
use App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1\SponsorRelationshipController;
use App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1\SponsorshipAgreementController;
use App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1\SponsorshipExposureController;
use App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1\SponsorshipFinancialController;
use App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1\SponsorshipReferenceController;
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

Route::prefix('tenants/{tenant}/sponsors/{sponsor}/agreements')->whereUuid(['tenant', 'sponsor'])->middleware('tenant')->group(function (): void {
    $agreements = SponsorshipAgreementController::class;
    Route::get('/', [$agreements, 'index'])->name('sponsorship-agreements.index');
    Route::post('/', [$agreements, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('sponsorship-agreements.store');
    Route::get('{agreement}', [$agreements, 'show'])->whereUuid('agreement')->name('sponsorship-agreements.show');
    Route::patch('{agreement}', [$agreements, 'update'])->whereUuid('agreement')->middleware('tenant.confirmed')->block(10, 10)->name('sponsorship-agreements.update');
    Route::post('{agreement}/transitions', [$agreements, 'transition'])->whereUuid('agreement')->middleware('tenant.confirmed')->block(10, 10)->name('sponsorship-agreements.transition');
    Route::get('{agreement}/history', [$agreements, 'history'])->whereUuid('agreement')->name('sponsorship-agreements.history');
    $exposures = SponsorshipExposureController::class;
    Route::get('{agreement}/exposures', [$exposures, 'index'])->whereUuid('agreement')->name('sponsorship-exposures.index');
    Route::post('{agreement}/exposures', [$exposures, 'store'])->whereUuid('agreement')->middleware('tenant.confirmed')->block(10, 10)->name('sponsorship-exposures.store');
    Route::patch('{agreement}/exposures/{exposure}', [$exposures, 'update'])->whereUuid(['agreement', 'exposure'])->middleware('tenant.confirmed')->block(10, 10)->name('sponsorship-exposures.update');
    $financial = SponsorshipFinancialController::class;
    Route::get('{agreement}/financial', [$financial, 'show'])->whereUuid('agreement')->name('sponsorship-financial.show');
    Route::patch('{agreement}/financial', [$financial, 'update'])->whereUuid('agreement')->middleware('tenant.confirmed')->block(10, 10)->name('sponsorship-financial.update');
});
Route::get('tenants/{tenant}/sponsorship-references/{kind}', [SponsorshipReferenceController::class, 'index'])
    ->whereUuid('tenant')->where('kind', 'members|teams|players|contracts')->middleware('tenant')->name('sponsorship-references.index');

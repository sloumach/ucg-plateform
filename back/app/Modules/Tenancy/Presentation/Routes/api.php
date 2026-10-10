<?php

use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
Route::get('organizations/{organization}', [OrganizationController::class, 'show'])->whereUuid('organization')->name('organizations.show');
Route::put('organizations/{organization}', [OrganizationController::class, 'update'])->whereUuid('organization')->name('organizations.update');

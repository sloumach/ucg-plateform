<?php

use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\OrganizationController;
use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\TenantContextController;
use Illuminate\Support\Facades\Route;

Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
Route::get('organizations/{organization}', [OrganizationController::class, 'show'])->whereUuid('organization')->name('organizations.show');
Route::put('organizations/{organization}', [OrganizationController::class, 'update'])
    ->whereUuid('organization')->middleware('tenant:organization')->name('organizations.update');

Route::get('tenants/{tenant}/context', [TenantContextController::class, 'show'])
    ->whereUuid('tenant')->middleware('tenant')->name('tenants.context');
Route::get('tenant/context', [TenantContextController::class, 'show'])
    ->middleware('tenant')->name('tenant.context');

<?php

use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\AccessibleOrganizationController;
use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\ActiveOrganizationController;
use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\InvitationController;
use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\MembershipController;
use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\MyInvitationController;
use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\MyMembershipController;
use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\OrganizationController;
use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\RoleAssignmentController;
use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\RoleController;
use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\SupportAccessController;
use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\SupportDiagnosticController;
use App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1\TenantContextController;
use Illuminate\Support\Facades\Route;

Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
Route::get('organizations/{organization}', [OrganizationController::class, 'show'])->whereUuid('organization')->name('organizations.show');
Route::put('organizations/{organization}', [OrganizationController::class, 'update'])
    ->whereUuid('organization')->middleware(['tenant:organization', 'tenant.confirmed'])->block(10, 10)->name('organizations.update');

Route::get('tenants/{tenant}/context', [TenantContextController::class, 'show'])
    ->whereUuid('tenant')->middleware('tenant')->block(10, 10)->name('tenants.context');
Route::get('tenant/context', [TenantContextController::class, 'show'])
    ->middleware('tenant')->block(10, 10)->name('tenant.context');

Route::get('accessible-organizations', [AccessibleOrganizationController::class, 'index'])->name('accessible-organizations.index');
Route::get('active-organization', [ActiveOrganizationController::class, 'show'])->block(10, 10)->name('active-organization.show');
Route::post('active-organization', [ActiveOrganizationController::class, 'store'])->block(10, 10)->name('active-organization.store');
Route::post('active-organization/confirmation', [ActiveOrganizationController::class, 'confirm'])->block(10, 10)->name('active-organization.confirm');
Route::get('my-invitations', [MyInvitationController::class, 'index'])->name('my-invitations.index');
Route::put('my-invitations/{invitation}', [MyInvitationController::class, 'update'])
    ->whereUuid('invitation')->block(10, 10)->name('my-invitations.update');
Route::delete('my-memberships/{membership}', [MyMembershipController::class, 'destroy'])
    ->whereUuid('membership')->block(10, 10)->name('my-memberships.destroy');

Route::prefix('tenants/{tenant}')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('permission-catalogue', [RoleController::class, 'catalogue'])->name('roles.catalogue');
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('roles.store');
    Route::put('roles/{role}', [RoleController::class, 'update'])->whereUuid('role')->middleware('tenant.confirmed')->block(10, 10)->name('roles.update');
    Route::get('role-assignments', [RoleAssignmentController::class, 'index'])->name('role-assignments.index');
    Route::get('my-role-assignments', [RoleAssignmentController::class, 'mine'])->name('role-assignments.mine');
    Route::post('role-assignments', [RoleAssignmentController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('role-assignments.store');
    Route::put('role-assignments/{assignment}', [RoleAssignmentController::class, 'update'])->whereUuid('assignment')->middleware('tenant.confirmed')->block(10, 10)->name('role-assignments.update');
    Route::get('memberships', [MembershipController::class, 'index'])->block(10, 10)->name('memberships.index');
    Route::put('memberships/{membership}', [MembershipController::class, 'update'])
        ->whereUuid('membership')->middleware('tenant.confirmed')->block(10, 10)->name('memberships.update');
    Route::get('invitations', [InvitationController::class, 'index'])->block(10, 10)->name('invitations.index');
    Route::post('invitations', [InvitationController::class, 'store'])
        ->middleware(['tenant.confirmed', 'throttle:tenant-invitations'])->block(10, 10)->name('invitations.store');
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])
        ->whereUuid('invitation')->middleware('tenant.confirmed')->block(10, 10)->name('invitations.destroy');
});

Route::prefix('platform')->middleware('throttle:platform-support')->group(function (): void {
    Route::get('support-accesses', [SupportAccessController::class, 'index'])->name('platform.support.index');
    Route::prefix('tenants/{tenant}/support-accesses')->whereUuid('tenant')->group(function (): void {
        Route::post('/', [SupportAccessController::class, 'store'])->block(10, 10)->name('platform.support.store');
        Route::delete('{access}', [SupportAccessController::class, 'destroy'])->whereUuid('access')->block(10, 10)->name('platform.support.destroy');
        Route::get('{access}/organization', [SupportDiagnosticController::class, 'organization'])->whereUuid('access')->block(10, 10)->name('platform.support.organization');
        Route::get('{access}/access-summary', [SupportDiagnosticController::class, 'accessSummary'])->whereUuid('access')->block(10, 10)->name('platform.support.access-summary');
    });
});

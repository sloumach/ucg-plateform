<?php

use App\Modules\Contracts\Presentation\Http\Controllers\Api\V1\ContractController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/contracts')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    $controller = ContractController::class;
    Route::get('/', [$controller, 'index'])->name('contracts.index');
    Route::get('people', [$controller, 'people'])->name('contracts.people');
    Route::get('{contract}', [$controller, 'show'])->whereUuid('contract')->name('contracts.show');
    Route::post('/', [$controller, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('contracts.store');
    Route::patch('{contract}', [$controller, 'update'])->whereUuid('contract')->middleware('tenant.confirmed')->block(10, 10)->name('contracts.update');
    Route::post('{contract}/transitions', [$controller, 'transition'])->whereUuid('contract')->middleware('tenant.confirmed')->block(10, 10)->name('contracts.transition');
    Route::get('{contract}/transitions', [$controller, 'transitions'])->whereUuid('contract')->name('contracts.transitions');
    Route::patch('{contract}/obligations/{obligation}', [$controller, 'obligation'])->whereUuid(['contract', 'obligation'])->middleware('tenant.confirmed')->block(10, 10)->name('contracts.obligation');
});

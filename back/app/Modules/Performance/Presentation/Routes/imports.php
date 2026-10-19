<?php

use App\Modules\Performance\Presentation\Http\Controllers\Api\V1\StatisticalImportController as Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/statistical-imports')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    Route::get('', [Controller::class, 'index'])->name('statistical-imports.index');
    Route::post('', [Controller::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('statistical-imports.store');
    Route::get('{import}', [Controller::class, 'show'])->whereUuid('import')->name('statistical-imports.show');
    foreach (['confirm', 'resume'] as $action) {
        Route::post('{import}/'.$action, [Controller::class, 'confirm'])->whereUuid('import')
            ->middleware('tenant.confirmed')->block(10, 10)->name('statistical-imports.'.$action);
    }
});

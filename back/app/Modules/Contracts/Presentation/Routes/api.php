<?php

use App\Modules\Contracts\Presentation\Http\Controllers\Api\V1\ContractController;
use App\Modules\Contracts\Presentation\Http\Controllers\Api\V1\ContractDocumentController;
use App\Modules\Contracts\Presentation\Http\Controllers\Api\V1\ContractDocumentSecurityController;
use App\Modules\Contracts\Presentation\Http\Controllers\Api\V1\ContractGovernanceController;
use App\Modules\Contracts\Presentation\Http\Controllers\Api\V1\ContractReminderController;
use App\Modules\Contracts\Presentation\Http\Middleware\ValidateDocumentDownloadLink;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant}/contracts')->whereUuid('tenant')->middleware('tenant')->group(function (): void {
    $controller = ContractController::class;
    $reminders = ContractReminderController::class;
    Route::get('reminders', [$reminders, 'inbox'])->name('contract-reminders.inbox');
    Route::get('{contract}/reminders', [$reminders, 'show'])->whereUuid('contract')->name('contract-reminders.show');
    Route::patch('{contract}/reminders', [$reminders, 'save'])->whereUuid('contract')->middleware('tenant.confirmed')->block(10, 10)->name('contract-reminders.save');
    Route::get('{contract}/reminders/history', [$reminders, 'history'])->whereUuid('contract')->name('contract-reminders.history');
    Route::post('{contract}/reminders/{delivery}/resolution', [$reminders, 'resolve'])->whereUuid(['contract', 'delivery'])->middleware('tenant.confirmed')->block(10, 10)->name('contract-reminders.resolve');
    $governance = ContractGovernanceController::class;
    foreach (['{contract}' => 'contracts', '{contract}/documents/{document}' => 'contract-documents'] as $prefix => $name) {
        Route::get($prefix.'/financial-terms', [$governance, 'financial'])->whereUuid(['contract', 'document'])->name($name.'.financial.show');
        Route::patch($prefix.'/financial-terms', [$governance, 'saveFinancial'])->whereUuid(['contract', 'document'])->middleware('tenant.confirmed')->block(10, 10)->name($name.'.financial.save');
    }
    Route::get('{contract}/documents/{document}/approvals', [$governance, 'approvals'])->whereUuid(['contract', 'document'])->name('contract-documents.approvals');
    Route::post('{contract}/documents/{document}/approvals', [$governance, 'approve'])->whereUuid(['contract', 'document'])->middleware('tenant.confirmed')->block(10, 10)->name('contract-documents.approve');
    Route::prefix('{contract}/documents')->whereUuid('contract')->group(function (): void {
        Route::prefix('{document}/versions/{version}')->whereUuid(['document', 'version'])->group(function (): void {
            Route::get('scans', [ContractDocumentSecurityController::class, 'index'])->name('contract-document-security.index');
            Route::post('scans', [ContractDocumentSecurityController::class, 'scan'])->middleware(['tenant.confirmed', 'throttle:document-scans'])->block(10, 10)->name('contract-document-security.scan');
            Route::post('download-link', [ContractDocumentSecurityController::class, 'link'])->middleware('throttle:document-links')->name('contract-document-security.link');
            Route::get('download', [ContractDocumentSecurityController::class, 'download'])->middleware(['throttle:document-downloads', ValidateDocumentDownloadLink::class])->name('contract-document-security.download');
        });
        Route::get('/', [ContractDocumentController::class, 'index'])->name('contract-documents.index');
        Route::post('/', [ContractDocumentController::class, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('contract-documents.store');
        Route::get('{document}/versions', [ContractDocumentController::class, 'versions'])->whereUuid('document')->name('contract-documents.versions');
        Route::post('{document}/versions', [ContractDocumentController::class, 'upload'])->whereUuid('document')->middleware(['tenant.confirmed', 'throttle:document-scans'])->block(10, 10)->name('contract-documents.upload');
        Route::post('{document}/signature', [ContractDocumentController::class, 'sign'])->whereUuid('document')->middleware('tenant.confirmed')->block(10, 10)->name('contract-documents.sign');
    });
    Route::get('/', [$controller, 'index'])->name('contracts.index');
    Route::get('people', [$controller, 'people'])->name('contracts.people');
    Route::get('{contract}', [$controller, 'show'])->whereUuid('contract')->name('contracts.show');
    Route::post('/', [$controller, 'store'])->middleware('tenant.confirmed')->block(10, 10)->name('contracts.store');
    Route::patch('{contract}', [$controller, 'update'])->whereUuid('contract')->middleware('tenant.confirmed')->block(10, 10)->name('contracts.update');
    Route::post('{contract}/transitions', [$controller, 'transition'])->whereUuid('contract')->middleware('tenant.confirmed')->block(10, 10)->name('contracts.transition');
    Route::get('{contract}/transitions', [$controller, 'transitions'])->whereUuid('contract')->name('contracts.transitions');
    Route::patch('{contract}/obligations/{obligation}', [$controller, 'obligation'])->whereUuid(['contract', 'obligation'])->middleware('tenant.confirmed')->block(10, 10)->name('contracts.obligation');
});

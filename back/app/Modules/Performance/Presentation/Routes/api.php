<?php

use App\Modules\Performance\Presentation\Http\Controllers\Api\V1\MetricCatalogueController as Controller;
use Illuminate\Support\Facades\Route;

Route::get('', [Controller::class, 'index'])->name('index');
Route::post('', [Controller::class, 'store'])->block(10, 10)->name('store');
Route::get('{metric}', [Controller::class, 'show'])->whereUuid('metric')->name('show');
Route::patch('{metric}', [Controller::class, 'update'])->whereUuid('metric')->block(10, 10)->name('update');
Route::get('{metric}/history', [Controller::class, 'history'])->whereUuid('metric')->name('history');
Route::get('{metric}/versions/{version}', [Controller::class, 'version'])->whereUuid(['metric', 'version'])->name('version');

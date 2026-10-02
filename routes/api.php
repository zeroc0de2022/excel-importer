<?php

declare(strict_types=1);

use App\Http\Controllers\ImportController;
use App\Http\Controllers\RowController;
use Illuminate\Support\Facades\Route;

Route::middleware('basicauth')->group(function () {
    Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
    Route::post('/imports', [ImportController::class, 'store'])->middleware('throttle:uploads')->name('imports.store');
    Route::get('/imports/{import}', [ImportController::class, 'show'])->name('imports.show');
    Route::get('/imports/{import}/report', [ImportController::class, 'report'])->name('imports.report');

    Route::get('/rows', [RowController::class, 'index'])->name('rows.index');
});

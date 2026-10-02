<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware('basicauth')->group(function () {
    Route::view('/', 'imports');
    Route::view('/rows', 'rows');
});

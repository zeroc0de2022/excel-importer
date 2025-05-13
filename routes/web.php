<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\DataController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::middleware('basicauth')->group(function () {
    Route::post('/upload', [UploadController::class, 'upload']);
});

Route::get('/data', [DataController::class, 'index']);
Route::get('/progress/{key}', [UploadController::class, 'progress']);


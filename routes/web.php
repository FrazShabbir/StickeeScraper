<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('scrape', [App\Http\Controllers\StickeeScraperController::class, 'scrape'])->name('scrape');
Route::get('scrape-v2', [App\Http\Controllers\StickeeScraperV2Controller::class, 'scrape'])->name('scrape.v2');


<?php

use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:120,1')
        ->name('generations.estimate');
    Route::post('generations/{generation}/retry', [GenerationController::class, 'retry'])->name('generations.retry');
});

require __DIR__.'/settings.php';

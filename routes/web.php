<?php

use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:120,1')
        ->name('generations.estimate');
    Route::get('credits', [CreditsController::class, 'index'])->name('credits.index');
    Route::post('credits', [CreditsController::class, 'store'])->name('credits.store');
    Route::post('generations/{generation}/retry', [GenerationController::class, 'retry'])->name('generations.retry');
});

// Admin area. The FormRequests check the same gate again.
Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('report', [ReportController::class, 'index'])->name('report');
    Route::get('reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation');
    Route::post('reconciliation/sync', [ReconciliationController::class, 'sync'])
        ->middleware('throttle:6,1')
        ->name('reconciliation.sync');
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
});

require __DIR__.'/settings.php';

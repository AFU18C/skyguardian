<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SourceController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::get('/sources', [SourceController::class, 'index'])->name('sources.index');
    Route::post('/sources', [SourceController::class, 'store'])->name('sources.store');
    Route::put('/sources/{source}', [SourceController::class, 'update'])->name('sources.update');
    Route::patch('/sources/{source}/toggle', [SourceController::class, 'toggle'])->name('sources.toggle');
    Route::delete('/sources/{source}', [SourceController::class, 'destroy'])->name('sources.destroy');

    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});

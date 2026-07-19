<?php

use App\Http\Controllers\EmailAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [EmailAuthController::class, 'show'])->name('login');
    Route::post('/login', [EmailAuthController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
});

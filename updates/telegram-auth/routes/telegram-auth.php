<?php

use App\Http\Controllers\TelegramAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [TelegramAuthController::class, 'show'])->name('login');
    Route::get('/auth/telegram/callback', [TelegramAuthController::class, 'callback'])
        ->name('telegram.auth.callback');
});

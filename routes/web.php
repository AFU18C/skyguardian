<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\TelegramDialogController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::get('/news/channels', [SourceController::class, 'news'])->name('news.channels.index');
    Route::get('/alerts/channels', [SourceController::class, 'alerts'])->name('alerts.channels.index');
    Route::post('/channels/{section}', [SourceController::class, 'store'])->name('channels.store');
    Route::put('/channels/{source}', [SourceController::class, 'update'])->name('channels.update');
    Route::patch('/channels/{source}/toggle', [SourceController::class, 'toggle'])->name('channels.toggle');
    Route::delete('/channels/{source}', [SourceController::class, 'destroy'])->name('channels.destroy');

    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::post('/integrations/telegram', [IntegrationController::class, 'store'])->name('integrations.telegram.store');
    Route::put('/integrations/telegram/{telegramAccount}', [IntegrationController::class, 'update'])->name('integrations.telegram.update');
    Route::post('/integrations/telegram/{telegramAccount}/phone/start', [IntegrationController::class, 'startPhone'])->name('integrations.telegram.phone.start');
    Route::post('/integrations/telegram/{telegramAccount}/phone/complete', [IntegrationController::class, 'completePhone'])->name('integrations.telegram.phone.complete');
    Route::post('/integrations/telegram/{telegramAccount}/password', [IntegrationController::class, 'completePassword'])->name('integrations.telegram.password');
    Route::get('/integrations/telegram/{telegramAccount}/qr', [IntegrationController::class, 'qr'])->name('integrations.telegram.qr');
    Route::post('/integrations/telegram/{telegramAccount}/disconnect', [IntegrationController::class, 'disconnect'])->name('integrations.telegram.disconnect');
    Route::delete('/integrations/telegram/{telegramAccount}', [IntegrationController::class, 'destroy'])->name('integrations.telegram.destroy');

    Route::get('/integrations/telegram/{telegramAccount}/dialogs', [TelegramDialogController::class, 'index'])->name('integrations.telegram.dialogs');
    Route::post('/integrations/telegram/{telegramAccount}/dialogs/source', [TelegramDialogController::class, 'store'])->name('integrations.telegram.dialogs.store');
    Route::get('/integrations/telegram/{telegramAccount}/messages', [TelegramDialogController::class, 'messages'])->name('integrations.telegram.messages');

    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});

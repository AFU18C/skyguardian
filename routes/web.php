<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\IntegrationController;
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

    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::post('/integrations/telegram', [IntegrationController::class, 'store'])->name('integrations.telegram.store');
    Route::put('/integrations/telegram/{telegramAccount}', [IntegrationController::class, 'update'])->name('integrations.telegram.update');
    Route::post('/integrations/telegram/{telegramAccount}/phone/start', [IntegrationController::class, 'startPhone'])->name('integrations.telegram.phone.start');
    Route::post('/integrations/telegram/{telegramAccount}/phone/complete', [IntegrationController::class, 'completePhone'])->name('integrations.telegram.phone.complete');
    Route::post('/integrations/telegram/{telegramAccount}/password', [IntegrationController::class, 'completePassword'])->name('integrations.telegram.password');
    Route::get('/integrations/telegram/{telegramAccount}/qr', [IntegrationController::class, 'qr'])->name('integrations.telegram.qr');
    Route::post('/integrations/telegram/{telegramAccount}/disconnect', [IntegrationController::class, 'disconnect'])->name('integrations.telegram.disconnect');
    Route::delete('/integrations/telegram/{telegramAccount}', [IntegrationController::class, 'destroy'])->name('integrations.telegram.destroy');

    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});

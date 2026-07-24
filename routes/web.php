<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\TelegramDialogController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::view('/admin/news/channels', 'admin.section', [
        'group' => 'Новости',
        'title' => 'Каналы данных',
        'description' => 'Раздел подготовлен для добавления источников новостей.',
    ])->name('news.channels');
    Route::view('/admin/news/settings', 'admin.news-settings')
        ->name('news.settings');

    Route::view('/admin/alerts/channels', 'admin.section', [
        'group' => 'Воздушная тревога',
        'title' => 'Каналы данных',
        'description' => 'Раздел подготовлен для добавления источников воздушной тревоги.',
    ])->name('alerts.channels');
    Route::view('/admin/alerts/settings', 'admin.section', [
        'group' => 'Воздушная тревога',
        'title' => 'Настройка',
        'description' => 'Раздел подготовлен для Telegram API и технических аккаунтов воздушной тревоги.',
    ])->name('alerts.settings');

    Route::view('/admin/settings/groups', 'admin.section', [
        'group' => 'Общие настройки',
        'title' => 'Управление группой',
        'description' => 'Раздел управления группой подготовлен к следующему этапу.',
    ])->name('settings.groups');
    Route::view('/admin/settings/site', 'admin.section', [
        'group' => 'Общие настройки',
        'title' => 'Управление сайтом',
        'description' => 'Раздел управления сайтом подготовлен к следующему этапу.',
    ])->name('settings.site');

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

    Route::get('/integrations/telegram/{telegramAccount}/dialogs', [TelegramDialogController::class, 'index'])->name('integrations.telegram.dialogs');
    Route::post('/integrations/telegram/{telegramAccount}/dialogs/source', [TelegramDialogController::class, 'store'])->name('integrations.telegram.dialogs.store');
    Route::get('/integrations/telegram/{telegramAccount}/messages', [TelegramDialogController::class, 'messages'])->name('integrations.telegram.messages');

    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});

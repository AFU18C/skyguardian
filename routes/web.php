<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\NewsChannelsController;
use App\Http\Controllers\NewsSettingsController;
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

    Route::get('/admin/news/channels', [NewsChannelsController::class, 'index'])
        ->name('news.channels');
    Route::get('/admin/news/channels/create', [NewsChannelsController::class, 'create'])
        ->name('news.channels.create');
    Route::post('/admin/news/channels', [NewsChannelsController::class, 'store'])
        ->name('news.channels.store');
    Route::get('/admin/news/channels/{channel}/edit', [NewsChannelsController::class, 'edit'])
        ->name('news.channels.edit');
    Route::put('/admin/news/channels/{channel}', [NewsChannelsController::class, 'update'])
        ->name('news.channels.update');
    Route::patch('/admin/news/channels/{channel}/toggle', [NewsChannelsController::class, 'toggle'])
        ->name('news.channels.toggle');
    Route::delete('/admin/news/channels/{channel}', [NewsChannelsController::class, 'destroy'])
        ->name('news.channels.destroy');

    Route::get('/admin/news/settings', [NewsSettingsController::class, 'index'])
        ->name('news.settings');
    Route::get('/admin/news/settings/create', [NewsSettingsController::class, 'create'])
        ->name('news.settings.create');
    Route::post('/admin/news/settings', [NewsSettingsController::class, 'store'])
        ->name('news.settings.store');
    Route::get('/admin/news/settings/{account}/edit', [NewsSettingsController::class, 'edit'])
        ->name('news.settings.edit');
    Route::put('/admin/news/settings/{account}', [NewsSettingsController::class, 'update'])
        ->name('news.settings.update');
    Route::patch('/admin/news/settings/{account}/toggle', [NewsSettingsController::class, 'toggle'])
        ->name('news.settings.toggle');
    Route::delete('/admin/news/settings/{account}', [NewsSettingsController::class, 'destroy'])
        ->name('news.settings.destroy');

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

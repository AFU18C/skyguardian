<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SourceController;
use App\Http\Controllers\Admin\SystemMetricsController;
use App\Http\Controllers\Admin\TelegramController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Models\Source;
use Illuminate\Support\Facades\Route;

Route::view('/', 'public.home')->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/admin/login', [AuthenticatedSessionController::class, 'create'])->name('admin.login');
    Route::post('/admin/login', [AuthenticatedSessionController::class, 'store'])->name('admin.login.store');
});

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/system/metrics', SystemMetricsController::class)->name('system.metrics');

    Route::get('/news', [SourceController::class, 'index'])->defaults('sourceType', Source::TYPE_NEWS)->name('news.index');
    Route::post('/news', [SourceController::class, 'store'])->defaults('sourceType', Source::TYPE_NEWS)->name('news.store');
    Route::put('/news/{source}', [SourceController::class, 'update'])->defaults('sourceType', Source::TYPE_NEWS)->name('news.update');
    Route::delete('/news/{source}', [SourceController::class, 'destroy'])->defaults('sourceType', Source::TYPE_NEWS)->name('news.destroy');
    Route::post('/news/{source}/check', [SourceController::class, 'check'])->defaults('sourceType', Source::TYPE_NEWS)->name('news.check');

    Route::get('/air-alert', [SourceController::class, 'index'])->defaults('sourceType', Source::TYPE_AIR_ALERT)->name('air-alert.index');
    Route::post('/air-alert', [SourceController::class, 'store'])->defaults('sourceType', Source::TYPE_AIR_ALERT)->name('air-alert.store');
    Route::put('/air-alert/{source}', [SourceController::class, 'update'])->defaults('sourceType', Source::TYPE_AIR_ALERT)->name('air-alert.update');
    Route::delete('/air-alert/{source}', [SourceController::class, 'destroy'])->defaults('sourceType', Source::TYPE_AIR_ALERT)->name('air-alert.destroy');
    Route::post('/air-alert/{source}/check', [SourceController::class, 'check'])->defaults('sourceType', Source::TYPE_AIR_ALERT)->name('air-alert.check');

    Route::get('/telegram', [TelegramController::class, 'index'])->name('telegram.index');
    Route::post('/telegram/apis', [TelegramController::class, 'storeApi'])->name('telegram.apis.store');
    Route::put('/telegram/apis/{telegramApi}', [TelegramController::class, 'updateApi'])->name('telegram.apis.update');
    Route::delete('/telegram/apis/{telegramApi}', [TelegramController::class, 'destroyApi'])->name('telegram.apis.destroy');
    Route::post('/telegram/accounts', [TelegramController::class, 'storeAccount'])->name('telegram.accounts.store');
    Route::put('/telegram/accounts/{account}', [TelegramController::class, 'updateAccount'])->name('telegram.accounts.update');
    Route::delete('/telegram/accounts/{account}', [TelegramController::class, 'destroyAccount'])->name('telegram.accounts.destroy');
    Route::post('/telegram/accounts/{account}/check', [TelegramController::class, 'checkAccount'])->name('telegram.accounts.check');
    Route::post('/telegram/accounts/{account}/send-code', [TelegramController::class, 'sendCode'])->name('telegram.accounts.send-code');
    Route::post('/telegram/accounts/{account}/sign-in', [TelegramController::class, 'signIn'])->name('telegram.accounts.sign-in');
    Route::post('/telegram/accounts/{account}/password', [TelegramController::class, 'signInPassword'])->name('telegram.accounts.password');
    Route::post('/telegram/accounts/{account}/qr/start', [TelegramController::class, 'startQr'])->name('telegram.accounts.qr.start');
    Route::post('/telegram/accounts/{account}/qr/wait', [TelegramController::class, 'waitQr'])->name('telegram.accounts.qr.wait');

    Route::view('/site-settings', 'admin.site-settings')->name('site-settings');
    Route::view('/group-channel', 'admin.group-channel')->name('group-channel');
});

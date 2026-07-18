<?php

use App\Http\Controllers\AlertBotSettingsController;
use App\Http\Controllers\AlertSourceController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.home')->name('home');

Route::view('/news', 'pages.news.index')->name('news.index');
Route::view('/news/sources', 'pages.news.sources')->name('news.sources');
Route::view('/news/sources/create', 'pages.news.source-create')->name('news.sources.create');
Route::view('/news/settings', 'pages.news.settings')->name('news.settings');

Route::view('/air-alert', 'pages.alerts.index')->name('alerts.index');
Route::get('/alerts/sources', [AlertSourceController::class, 'index'])->name('alerts.sources');
Route::get('/alerts/sources/create', [AlertSourceController::class, 'create'])->name('alerts.sources.create');
Route::post('/alerts/sources', [AlertSourceController::class, 'store'])->name('alerts.sources.store');
Route::get('/alerts/sources/{source}/edit', [AlertSourceController::class, 'edit'])->name('alerts.sources.edit');
Route::put('/alerts/sources/{source}', [AlertSourceController::class, 'update'])->name('alerts.sources.update');
Route::delete('/alerts/sources/{source}', [AlertSourceController::class, 'destroy'])->name('alerts.sources.destroy');
Route::post('/alerts/sources/{source}/test', [AlertSourceController::class, 'test'])->name('alerts.sources.test');
Route::get('/alerts/settings', [AlertBotSettingsController::class, 'edit'])->name('alerts.settings');
Route::post('/alerts/settings', [AlertBotSettingsController::class, 'update'])->name('alerts.settings.update');
Route::post('/alerts/settings/test', [AlertBotSettingsController::class, 'test'])->name('alerts.settings.test');
Route::delete('/alerts/settings/token', [AlertBotSettingsController::class, 'destroyToken'])->name('alerts.settings.token.destroy');

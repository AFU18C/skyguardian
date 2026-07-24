<?php

namespace App\Providers;

use App\Contracts\TelegramGateway;
use App\Services\MadelineTelegramGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TelegramGateway::class, MadelineTelegramGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

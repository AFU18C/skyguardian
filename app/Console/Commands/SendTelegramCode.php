<?php

namespace App\Console\Commands;

use App\Models\TechnicalAccount;
use App\Services\TelegramAuthService;
use Illuminate\Console\Command;
use Throwable;

class SendTelegramCode extends Command
{
    protected $signature = 'skyguardian:telegram:send-code {account : ID технического аккаунта}';

    protected $description = 'Запросить код авторизации Telegram по номеру телефона';

    public function handle(TelegramAuthService $service): int
    {
        $account = TechnicalAccount::query()->findOrFail((int) $this->argument('account'));

        try {
            $service->sendCode($account);
            $this->info('Код авторизации отправлен.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}

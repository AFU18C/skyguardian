<?php

namespace App\Console\Commands;

use App\Models\TechnicalAccount;
use App\Services\TelegramAuthService;
use Illuminate\Console\Command;
use Throwable;

class SignInTelegramPassword extends Command
{
    protected $signature = 'skyguardian:telegram:password {account : ID технического аккаунта}';

    protected $description = 'Завершить авторизацию Telegram паролем двухэтапной аутентификации';

    public function handle(TelegramAuthService $service): int
    {
        $account = TechnicalAccount::query()->findOrFail((int) $this->argument('account'));
        $password = (string) $this->secret('Пароль двухэтапной аутентификации');

        try {
            $service->signInPassword($account, $password);
            $this->info('Telegram-аккаунт подключён.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}

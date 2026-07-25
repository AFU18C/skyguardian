<?php

namespace App\Console\Commands;

use App\Models\TechnicalAccount;
use App\Services\TelegramAuthService;
use Illuminate\Console\Command;
use Throwable;

class SignInTelegram extends Command
{
    protected $signature = 'skyguardian:telegram:sign-in {account : ID технического аккаунта} {code : Код из Telegram}';

    protected $description = 'Подтвердить авторизацию Telegram кодом';

    public function handle(TelegramAuthService $service): int
    {
        $account = TechnicalAccount::query()->findOrFail((int) $this->argument('account'));

        try {
            $account = $service->signIn($account, (string) $this->argument('code'));
            $this->info($account->status === 'awaiting_password'
                ? 'Требуется пароль двухэтапной аутентификации.'
                : 'Telegram-аккаунт подключён.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}

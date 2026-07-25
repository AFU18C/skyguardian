<?php

namespace App\Console\Commands;

use App\Models\TechnicalAccount;
use App\Services\TelegramAuthService;
use Illuminate\Console\Command;
use Throwable;

class SignInTelegramQr extends Command
{
    protected $signature = 'skyguardian:telegram:qr {account : ID технического аккаунта}';

    protected $description = 'Авторизовать технический Telegram-аккаунт по QR-коду';

    public function handle(TelegramAuthService $service): int
    {
        $account = TechnicalAccount::query()->findOrFail((int) $this->argument('account'));

        try {
            $result = $service->startQr($account);
            $this->line('QR URL: '.$result['url']);
            $this->line('Откройте URL как QR-код и отсканируйте его в Telegram.');

            while (true) {
                $wait = $service->waitQr($account->refresh(), 20);

                if ($wait['status'] === 'connected') {
                    $this->info('Telegram-аккаунт подключён.');
                    return self::SUCCESS;
                }

                if ($wait['status'] === 'awaiting_password') {
                    $password = (string) $this->secret('Пароль двухэтапной аутентификации');
                    $service->signInPassword($account->refresh(), $password);
                    $this->info('Telegram-аккаунт подключён.');
                    return self::SUCCESS;
                }

                if ($wait['status'] === 'expired') {
                    $this->error('Срок действия QR-кода истёк.');
                    return self::FAILURE;
                }

                $this->line('Ожидание сканирования...');
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}

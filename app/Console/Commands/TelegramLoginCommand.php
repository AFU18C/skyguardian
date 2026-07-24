<?php

namespace App\Console\Commands;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class TelegramLoginCommand extends Command
{
    protected $signature = 'telegram:login';

    protected $description = 'Authorize the SkyGuardian Telegram session through MadelineProto';

    public function handle(): int
    {
        if (! class_exists(API::class)) {
            $this->error('MadelineProto не установлен.');

            return self::FAILURE;
        }

        $directory = storage_path('app/telegram');
        File::ensureDirectoryExists($directory, 0700, true);

        $session = $directory.'/session.madeline';
        $apiId = (int) $this->ask('Telegram API ID');
        $apiHash = (string) $this->secret('Telegram API Hash');

        if ($apiId <= 0 || $apiHash === '') {
            $this->error('API ID и API Hash обязательны.');

            return self::FAILURE;
        }

        $settings = (new AppInfo)
            ->setApiId($apiId)
            ->setApiHash($apiHash)
            ->setDeviceModel('SkyGuardian VPS')
            ->setAppVersion('1.0')
            ->setLangCode('ru')
            ->setSystemLangCode('ru');

        try {
            $api = new API($session, $settings);

            $this->info('Далее MadelineProto запросит номер телефона, код Telegram и пароль 2FA, если он включён.');
            $api->start();

            $user = $api->getSelf();

            if (! is_array($user)) {
                $this->error('Telegram-сессия не авторизована.');

                return self::FAILURE;
            }

            @chmod($directory, 0700);

            $name = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''));
            $username = isset($user['username']) ? '@'.$user['username'] : 'без username';

            $this->newLine();
            $this->info('Telegram успешно подключён.');
            $this->line('Аккаунт: '.($name !== '' ? $name : 'Без имени').' ('.$username.')');
            $this->line('Сессия: storage/app/telegram/session.madeline');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Ошибка подключения: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}

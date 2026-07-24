<?php

namespace App\Services;

use App\Models\TelegramAccount;
use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use Illuminate\Support\Facades\File;
use RuntimeException;

class TelegramSessionService
{
    /** @var array<int, API> */
    private array $instances = [];

    public function api(TelegramAccount $account): API
    {
        if (! class_exists(API::class)) {
            throw new RuntimeException('Telegram-клиент не установлен на сервере.');
        }

        if (isset($this->instances[$account->id])) {
            return $this->instances[$account->id];
        }

        $account->loadMissing('telegramApp');
        $apiId = $account->apiIdValue();
        $apiHash = $account->apiHashValue();

        if ($apiId === '' || $apiHash === '') {
            throw new RuntimeException('Для технического аккаунта не настроены Telegram API ID и API Hash.');
        }

        $this->restoreEncryptedSnapshot($account);

        $appInfo = (new AppInfo())
            ->setApiId((int) $apiId)
            ->setApiHash($apiHash)
            ->setDeviceModel('SkyGuardian')
            ->setAppVersion('1.0')
            ->setLangCode('ru')
            ->setSystemLangCode('ru');

        $settings = (new Settings())->setAppInfo($appInfo);
        $settings->getRpc()->setFloodTimeout(5);
        $settings->getSerialization()->setInterval(30);

        return $this->instances[$account->id] = new API($account->sessionPath(), $settings);
    }

    public function checkpoint(API $api): void
    {
        $api->serialize();
    }

    public function persistEncryptedSnapshot(TelegramAccount $account, API $api): void
    {
        $api->serialize();

        $sessionPath = $account->sessionPath();
        $files = [];

        foreach (File::files($sessionPath) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $files[$file->getFilename()] = base64_encode(File::get($file->getPathname()));
        }

        if ($files === []) {
            throw new RuntimeException('Telegram не создал данные сессии.');
        }

        $account->update([
            'session_payload' => json_encode($files, JSON_THROW_ON_ERROR),
            'session_saved_at' => now(),
        ]);
    }

    public function markConnected(TelegramAccount $account, API $api): void
    {
        $self = $api->getSelf();
        $this->persistEncryptedSnapshot($account, $api);

        $account->update([
            'status' => 'connected',
            'is_active' => true,
            'telegram_name' => trim(($self['first_name'] ?? '').' '.($self['last_name'] ?? '')) ?: null,
            'telegram_username' => $self['username'] ?? null,
            'last_error' => null,
            'connected_at' => now(),
            'last_success_at' => now(),
            'flood_wait_until' => null,
        ]);

        $account->sources()
            ->where('purpose', 'news')
            ->where('is_active', true)
            ->update(['next_check_at' => now()]);
    }

    public function markError(TelegramAccount $account, \Throwable $exception): void
    {
        $wait = TelegramFloodWait::seconds($exception);

        if ($wait !== null) {
            $account->update([
                'status' => 'rate_limited',
                'flood_wait_until' => now()->addSeconds($wait),
                'last_error' => null,
                'last_attempt_at' => now(),
            ]);

            return;
        }

        $account->update([
            'status' => 'error',
            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            'last_attempt_at' => now(),
        ]);
    }

    public function purge(TelegramAccount $account): void
    {
        unset($this->instances[$account->id]);
        File::deleteDirectory($account->sessionPath());

        $account->update([
            'session_payload' => null,
            'session_saved_at' => null,
        ]);
    }

    private function restoreEncryptedSnapshot(TelegramAccount $account): void
    {
        $sessionPath = $account->sessionPath();
        File::ensureDirectoryExists(dirname($sessionPath), 0700, true);
        File::ensureDirectoryExists($sessionPath, 0700, true);
        @chmod(dirname($sessionPath), 0700);
        @chmod($sessionPath, 0700);

        if (File::exists($sessionPath.'/safe.php') || blank($account->session_payload)) {
            return;
        }

        $files = json_decode((string) $account->session_payload, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($files)) {
            throw new RuntimeException('Сохранённая Telegram-сессия повреждена.');
        }

        foreach ($files as $name => $contents) {
            if (! is_string($name) || ! preg_match('/^[a-zA-Z0-9_.-]+\.php$/', $name)) {
                continue;
            }

            $decoded = base64_decode((string) $contents, true);

            if ($decoded === false) {
                throw new RuntimeException('Сохранённая Telegram-сессия повреждена.');
            }

            File::put($sessionPath.'/'.$name, $decoded, true);
            @chmod($sessionPath.'/'.$name, 0600);
        }
    }
}

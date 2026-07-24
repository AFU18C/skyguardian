<?php

namespace App\Services;

use App\Models\TelegramAccount;
use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;
use Illuminate\Support\Facades\File;

class TelegramSessionService
{
    public function api(TelegramAccount $account): API
    {
        File::ensureDirectoryExists(dirname($account->sessionPath()), 0700, true);

        $settings = (new AppInfo())
            ->setApiId((int) $account->api_id)
            ->setApiHash($account->api_hash)
            ->setDeviceModel('SkyGuardian')
            ->setAppVersion('0.6')
            ->setLangCode('ru')
            ->setSystemLangCode('ru');

        return new API($account->sessionPath(), $settings);
    }

    public function markConnected(TelegramAccount $account, API $api): void
    {
        $self = $api->getSelf();

        $account->update([
            'status' => 'connected',
            'telegram_name' => trim(($self['first_name'] ?? '').' '.($self['last_name'] ?? '')) ?: null,
            'telegram_username' => $self['username'] ?? null,
            'last_error' => null,
            'connected_at' => now(),
        ]);
    }

    public function markError(TelegramAccount $account, \Throwable $exception): void
    {
        $account->update([
            'status' => 'error',
            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }
}
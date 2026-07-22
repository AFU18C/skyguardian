<?php
declare(strict_types=1);

namespace SkyGuardian\Telegram;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;

final class MadelineAccountClient
{
    public function connect(array $account): API
    {
        $settings = new Settings();
        $settings->getAppInfo()
            ->setApiId((int) $account['api_id'])
            ->setApiHash((string) $account['api_hash']);

        return new API((string) $account['session_path'], $settings);
    }

    public function verify(array $account): array
    {
        $api = $this->connect($account);
        $api->start();
        $self = $api->getSelf();
        return [
            'id' => $self['id'] ?? null,
            'username' => $self['username'] ?? null,
            'first_name' => $self['first_name'] ?? null,
        ];
    }

    public function qrLogin(array $account): mixed
    {
        return $this->connect($account)->qrLogin();
    }
}
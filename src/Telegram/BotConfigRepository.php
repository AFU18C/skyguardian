<?php
declare(strict_types=1);

namespace SkyGuardian\Telegram;

use SkyGuardian\Storage\JsonStore;

final class BotConfigRepository
{
    public function __construct(private readonly JsonStore $store) {}

    public function get(): array
    {
        return $this->store->read('telegram_bot');
    }

    public function save(array $config): void
    {
        $this->store->write('telegram_bot', [
            'token' => trim((string) ($config['token'] ?? '')),
            'chat_id' => trim((string) ($config['chat_id'] ?? '')),
            'enabled' => (bool) ($config['enabled'] ?? false),
            'mode' => in_array(($config['mode'] ?? 'webhook'), ['webhook','polling'], true) ? $config['mode'] : 'webhook',
            'webhook_secret' => trim((string) ($config['webhook_secret'] ?? '')),
        ]);
    }
}

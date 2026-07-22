<?php
declare(strict_types=1);

namespace SkyGuardian\Telegram;

use SkyGuardian\Storage\JsonStore;

final class BotConfigRepository
{
    public function __construct(private readonly JsonStore $store) {}

    public function get(): array
    {
        return array_replace([
            'token' => '', 'chat_id' => '', 'enabled' => false,
            'mode' => 'webhook', 'webhook_secret' => '', 'polling_offset' => 0,
        ], $this->store->read('bot-config'));
    }

    public function save(array $config): void
    {
        $current = $this->get();
        $this->store->write('bot-config', [
            'token' => trim((string) ($config['token'] ?? $current['token'])),
            'chat_id' => trim((string) ($config['chat_id'] ?? $current['chat_id'])),
            'enabled' => (bool) ($config['enabled'] ?? $current['enabled']),
            'mode' => in_array(($config['mode'] ?? $current['mode']), ['webhook','polling'], true) ? ($config['mode'] ?? $current['mode']) : 'webhook',
            'webhook_secret' => trim((string) ($config['webhook_secret'] ?? $current['webhook_secret'])),
            'polling_offset' => max(0, (int) ($config['polling_offset'] ?? $current['polling_offset'])),
        ]);
    }
}

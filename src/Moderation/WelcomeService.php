<?php
declare(strict_types=1);

namespace SkyGuardian\Moderation;

use SkyGuardian\Storage\JsonStore;
use SkyGuardian\Telegram\BotApiClient;

final class WelcomeService
{
    public function __construct(private readonly JsonStore $store, private readonly BotApiClient $telegram) {}

    public function send(string $chatId, array $user, string $template, int $deleteAfterSeconds = 0): int
    {
        $text = strtr($template, [
            '{first_name}' => (string) ($user['first_name'] ?? ''),
            '{last_name}' => (string) ($user['last_name'] ?? ''),
            '{username}' => isset($user['username']) ? '@' . $user['username'] : '',
            '{user_id}' => (string) ($user['id'] ?? ''),
        ]);
        $result = $this->telegram->call('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
        $messageId = (int) ($result['result']['message_id'] ?? 0);
        if ($deleteAfterSeconds > 0 && $messageId > 0) {
            $queue = $this->store->read('deletion_queue');
            $queue[] = ['chat_id' => $chatId, 'message_id' => $messageId, 'delete_at' => time() + $deleteAfterSeconds];
            $this->store->write('deletion_queue', $queue);
        }
        return $messageId;
    }

    public function processDeletionQueue(): int
    {
        $queue = $this->store->read('deletion_queue');
        $pending = [];
        $deleted = 0;
        foreach ($queue as $item) {
            if ((int) ($item['delete_at'] ?? 0) > time()) {
                $pending[] = $item;
                continue;
            }
            $this->telegram->call('deleteMessage', ['chat_id' => $item['chat_id'], 'message_id' => $item['message_id']]);
            $deleted++;
        }
        $this->store->write('deletion_queue', $pending);
        return $deleted;
    }
}

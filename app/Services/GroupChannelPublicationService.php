<?php

namespace App\Services;

use App\Models\GroupChannelPublication;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GroupChannelPublicationService
{
    public function send(GroupChannelPublication $publication): void
    {
        $publication->loadMissing('bot');
        $bot = $publication->bot;

        if (! $bot || ! $bot->is_active) {
            throw new RuntimeException('Бот отключён или удалён.');
        }

        if (! $bot->moduleEnabled('publications')) {
            throw new RuntimeException('Модуль публикаций выключен для этого чата.');
        }

        if (! $bot->chat_id) {
            throw new RuntimeException('Сначала выполните ручную проверку подключения, чтобы определить Chat ID.');
        }

        try {
            $response = Http::baseUrl('https://api.telegram.org/bot'.$bot->bot_token)
                ->acceptJson()
                ->timeout(20)
                ->post('sendMessage', [
                    'chat_id' => $bot->chat_id,
                    'text' => $publication->text,
                ])
                ->throw()
                ->json();

            if (! ($response['ok'] ?? false)) {
                throw new RuntimeException($response['description'] ?? 'Ошибка Telegram API');
            }

            $publication->update([
                'status' => GroupChannelPublication::STATUS_SENT,
                'sent_at' => now(),
                'telegram_message_id' => (string) data_get($response, 'result.message_id'),
                'last_error' => null,
            ]);
        } catch (Throwable $e) {
            $publication->update([
                'status' => GroupChannelPublication::STATUS_ERROR,
                'last_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

<?php

namespace App\Services;

use App\Models\GroupChannelPublication;
use Illuminate\Http\Client\PendingRequest;
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
            $response = $this->telegram($bot->bot_token)->post('sendMessage', [
                'chat_id' => $bot->chat_id,
                'text' => $publication->text,
            ])->throw()->json();

            if (! ($response['ok'] ?? false)) {
                throw new RuntimeException($response['description'] ?? 'Ошибка Telegram API');
            }

            $sentAt = now();
            $publication->update([
                'status' => GroupChannelPublication::STATUS_SENT,
                'sent_at' => $sentAt,
                'delete_at' => $publication->delete_after_minutes
                    ? $sentAt->copy()->addMinutes($publication->delete_after_minutes)
                    : null,
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

    public function delete(GroupChannelPublication $publication): void
    {
        $publication->loadMissing('bot');
        $bot = $publication->bot;

        if (! $bot || ! $bot->is_active || ! $bot->chat_id || ! $publication->telegram_message_id) {
            throw new RuntimeException('Недостаточно данных для удаления публикации.');
        }

        if (! $bot->moduleEnabled('auto_delete_publications')) {
            throw new RuntimeException('Модуль автоудаления выключен для этого чата.');
        }

        try {
            $response = $this->telegram($bot->bot_token)->post('deleteMessage', [
                'chat_id' => $bot->chat_id,
                'message_id' => $publication->telegram_message_id,
            ])->throw()->json();

            if (! ($response['ok'] ?? false)) {
                throw new RuntimeException($response['description'] ?? 'Ошибка Telegram API');
            }

            $publication->update([
                'deleted_at_telegram' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $e) {
            $publication->update(['last_error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function telegram(string $token): PendingRequest
    {
        return Http::baseUrl('https://api.telegram.org/bot'.$token)
            ->acceptJson()
            ->timeout(20);
    }
}

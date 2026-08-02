<?php

namespace App\Services;

use App\Models\GroupChannelPublication;
use RuntimeException;
use Throwable;

class GroupChannelPublicationService
{
    public function __construct(private readonly GroupChannelTelegramService $telegram) {}

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

        if ($publication->type === GroupChannelPublication::TYPE_POLL && ! $bot->moduleEnabled('polls')) {
            throw new RuntimeException('Модуль опросов выключен для этого чата.');
        }

        if (! $bot->chat_id) {
            throw new RuntimeException('Сначала выполните ручную проверку подключения, чтобы определить Chat ID.');
        }

        $this->claimForSending($publication);

        try {
            $result = $this->sendByType($publication);
            $messageIds = $this->messageIds($result);
            $reactionError = $this->applyReactions($publication, $messageIds);
            $sentAt = now();

            $publication->update([
                'status' => GroupChannelPublication::STATUS_SENT,
                'sending_started_at' => null,
                'sent_at' => $sentAt,
                'delete_at' => $publication->delete_after_minutes
                    ? $sentAt->copy()->addMinutes($publication->delete_after_minutes)
                    : null,
                'telegram_message_id' => isset($messageIds[0]) ? (string) $messageIds[0] : null,
                'telegram_message_ids' => array_map('strval', $messageIds),
                'last_error' => $reactionError,
            ]);
        } catch (Throwable $e) {
            $publication->update([
                'status' => GroupChannelPublication::STATUS_ERROR,
                'sending_started_at' => null,
                'last_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function delete(GroupChannelPublication $publication): void
    {
        $publication->loadMissing('bot');
        $bot = $publication->bot;
        $messageIds = $publication->telegram_message_ids ?: array_filter([$publication->telegram_message_id]);

        if (! $bot || ! $bot->is_active || ! $bot->chat_id || $messageIds === []) {
            throw new RuntimeException('Недостаточно данных для удаления публикации.');
        }

        if (! $bot->moduleEnabled('auto_delete_publications')) {
            throw new RuntimeException('Модуль автоудаления выключен для этого чата.');
        }

        $remainingIds = array_values(array_map('strval', $messageIds));
        $errors = [];

        foreach ($remainingIds as $messageId) {
            try {
                $this->telegram->request($bot, 'deleteMessage', [
                    'chat_id' => $bot->chat_id,
                    'message_id' => $messageId,
                ]);
                $remainingIds = array_values(array_diff($remainingIds, [$messageId]));
                $publication->update(['telegram_message_ids' => $remainingIds]);
            } catch (Throwable $e) {
                if ($this->alreadyDeleted($e)) {
                    $remainingIds = array_values(array_diff($remainingIds, [$messageId]));
                    $publication->update(['telegram_message_ids' => $remainingIds]);

                    continue;
                }

                $errors[] = '#'.$messageId.': '.$e->getMessage();
            }
        }

        if ($remainingIds === []) {
            $publication->update([
                'deleted_at_telegram' => now(),
                'last_error' => null,
            ]);

            return;
        }

        $message = 'Не удалены сообщения: '.implode('; ', $errors);
        $publication->update(['last_error' => $message]);

        throw new RuntimeException($message);
    }

    private function claimForSending(GroupChannelPublication $publication): void
    {
        $claimed = GroupChannelPublication::query()
            ->whereKey($publication->id)
            ->whereNull('sent_at')
            ->where(function ($query): void {
                $query->whereIn('status', [
                    GroupChannelPublication::STATUS_DRAFT,
                    GroupChannelPublication::STATUS_SCHEDULED,
                    GroupChannelPublication::STATUS_ERROR,
                ])->orWhere(function ($query): void {
                    $query->where('status', GroupChannelPublication::STATUS_SENDING)
                        ->where('sending_started_at', '<=', now()->subMinutes(10));
                });
            })
            ->update([
                'status' => GroupChannelPublication::STATUS_SENDING,
                'sending_started_at' => now(),
                'last_error' => null,
            ]);

        if ($claimed === 1) {
            $publication->refresh()->loadMissing('bot');

            return;
        }

        $publication->refresh();
        if ($publication->status === GroupChannelPublication::STATUS_SENT || $publication->sent_at) {
            throw new RuntimeException('Публикация уже отправлена.');
        }

        throw new RuntimeException('Публикация уже отправляется другим процессом.');
    }

    private function alreadyDeleted(Throwable $error): bool
    {
        $message = mb_strtolower($error->getMessage());

        return str_contains($message, 'message to delete not found')
            || str_contains($message, 'message_id_invalid');
    }

    private function sendByType(GroupChannelPublication $publication): array
    {
        $bot = $publication->bot;
        $common = array_filter([
            'chat_id' => $bot->chat_id,
            'disable_notification' => $publication->disable_notification,
            'reply_markup' => $this->replyMarkup($publication->buttons),
        ], fn (mixed $value): bool => $value !== null);
        $paths = array_values($publication->media_paths ?? []);

        return match ($publication->type) {
            GroupChannelPublication::TYPE_PHOTO => $this->telegram->upload(
                $bot,
                'sendPhoto',
                'photo',
                (string) data_get($paths, '0.path', data_get($paths, '0')),
                array_merge($common, ['caption' => $publication->text]),
            ),
            GroupChannelPublication::TYPE_VIDEO => $this->telegram->upload(
                $bot,
                'sendVideo',
                'video',
                (string) data_get($paths, '0.path', data_get($paths, '0')),
                array_merge($common, ['caption' => $publication->text]),
            ),
            GroupChannelPublication::TYPE_DOCUMENT => $this->telegram->upload(
                $bot,
                'sendDocument',
                'document',
                (string) data_get($paths, '0.path', data_get($paths, '0')),
                array_merge($common, ['caption' => $publication->text]),
            ),
            GroupChannelPublication::TYPE_ALBUM => $this->telegram->sendMediaGroup(
                $bot,
                collect($paths)->map(function (mixed $item, int $index) use ($publication): array {
                    $path = is_array($item) ? (string) ($item['path'] ?? '') : (string) $item;
                    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                    return [
                        'path' => $path,
                        'type' => in_array($extension, ['mp4', 'mov', 'm4v', 'webm'], true) ? 'video' : 'photo',
                        'caption' => $index === 0 ? $publication->text : null,
                    ];
                })->all(),
                array_diff_key($common, ['reply_markup' => true]),
            ),
            GroupChannelPublication::TYPE_POLL => $this->telegram->request($bot, 'sendPoll', array_merge(
                $common,
                $this->pollPayload($publication->poll ?? []),
            )),
            default => $this->telegram->request($bot, 'sendMessage', array_merge($common, [
                'text' => $publication->text,
            ])),
        };
    }

    private function applyReactions(GroupChannelPublication $publication, array $messageIds): ?string
    {
        if (! $publication->reactions || $messageIds === []) {
            return null;
        }

        $reaction = collect($publication->reactions)
            ->filter(fn (mixed $emoji): bool => is_string($emoji) && $emoji !== '')
            ->map(fn (string $emoji): array => ['type' => 'emoji', 'emoji' => $emoji])
            ->values()
            ->all();

        if ($reaction === []) {
            return null;
        }

        try {
            foreach ($messageIds as $messageId) {
                $this->telegram->request($publication->bot, 'setMessageReaction', [
                    'chat_id' => $publication->bot->chat_id,
                    'message_id' => $messageId,
                    'reaction' => $reaction,
                ]);
            }
        } catch (Throwable $e) {
            report($e);

            return 'Публикация отправлена, но реакции не установлены: '.$e->getMessage();
        }

        return null;
    }

    private function pollPayload(array $poll): array
    {
        $options = array_values(array_filter(
            $poll['options'] ?? [],
            fn (mixed $option): bool => is_string($option) && trim($option) !== '',
        ));

        if (count($options) < 2) {
            throw new RuntimeException('Для опроса необходимо минимум два варианта ответа.');
        }

        $payload = [
            'question' => (string) ($poll['question'] ?? ''),
            'options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_anonymous' => (bool) ($poll['is_anonymous'] ?? true),
            'type' => ($poll['type'] ?? 'regular') === 'quiz' ? 'quiz' : 'regular',
        ];

        if ($payload['type'] === 'quiz') {
            $payload['correct_option_id'] = max(0, (int) ($poll['correct_option_id'] ?? 0));
        }

        if (! empty($poll['open_period'])) {
            $payload['open_period'] = min(600, max(5, (int) $poll['open_period']));
        }

        return $payload;
    }

    private function replyMarkup(?array $buttons): ?string
    {
        if (! $buttons) {
            return null;
        }

        $rows = collect($buttons)
            ->map(function (mixed $row): array {
                $items = is_array($row) && array_is_list($row) ? $row : [$row];

                return collect($items)->map(function (mixed $button): array {
                    if (! is_array($button)) {
                        return [];
                    }

                    return array_filter([
                        'text' => (string) ($button['text'] ?? ''),
                        'url' => $button['url'] ?? null,
                        'callback_data' => $button['callback_data'] ?? null,
                    ], fn (mixed $value): bool => $value !== null && $value !== '');
                })->filter()->values()->all();
            })
            ->filter()
            ->values()
            ->all();

        return $rows === [] ? null : json_encode(
            ['inline_keyboard' => $rows],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function messageIds(array $result): array
    {
        if (array_is_list($result)) {
            return collect($result)
                ->pluck('message_id')
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all();
        }

        $messageId = data_get($result, 'message_id');

        return $messageId ? [(int) $messageId] : [];
    }
}

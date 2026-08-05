<?php

namespace App\Services;

use App\Models\GroupChannelBot;
use App\Models\Source;
use Throwable;

class SourceMapButtonService
{
    public const BUTTON_TEXT = '🗺 Мапа тривог України';

    public function __construct(
        private readonly GroupChannelTelegramService $telegram,
    ) {}

    public function attach(Source $source, int $messageId, string $url): ?string
    {
        $url = trim($url);

        if ($messageId < 1 || ! $this->isSafeUrl($url)) {
            return 'Указана некорректная ссылка кнопки.';
        }

        $bot = $this->findDestinationBot((string) $source->destination_peer);

        if ($bot === null) {
            return 'Для канала назначения не найден активный Bot API в разделе «Группа-Канал».';
        }

        try {
            $this->telegram->request($bot, 'editMessageReplyMarkup', [
                'chat_id' => $bot->chat_id,
                'message_id' => $messageId,
                'reply_markup' => [
                    'inline_keyboard' => [[
                        [
                            'text' => self::BUTTON_TEXT,
                            'url' => $url,
                        ],
                    ]],
                ],
            ]);

            return null;
        } catch (Throwable $exception) {
            report($exception);

            return 'Telegram не разрешил добавить кнопку. Проверьте право бота редактировать сообщения канала.';
        }
    }

    private function findDestinationBot(string $destinationPeer): ?GroupChannelBot
    {
        $destination = $this->normalizePeer($destinationPeer);

        if ($destination === '') {
            return null;
        }

        return GroupChannelBot::query()
            ->where('is_active', true)
            ->where('chat_type', 'channel')
            ->get()
            ->first(function (GroupChannelBot $bot) use ($destination): bool {
                $chatId = trim((string) $bot->chat_id);

                if ($chatId !== '' && $destination === mb_strtolower($chatId)) {
                    return true;
                }

                return $destination === $this->normalizePeer((string) $bot->group_link);
            });
    }

    private function normalizePeer(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^-?\d+$/', $value)) {
            return mb_strtolower($value);
        }

        if (str_contains($value, '://')) {
            $path = trim((string) parse_url($value, PHP_URL_PATH), '/');
            $segments = array_values(array_filter(explode('/', $path)));
            $value = (string) end($segments);
        }

        return mb_strtolower(ltrim(trim($value), '@'));
    }

    private function isSafeUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}

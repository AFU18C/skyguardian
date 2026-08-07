<?php

namespace App\Services;

use App\Models\GroupChannelBot;

/**
 * Прямой Telegram Bot API клиент для внутренних публикаций тревог, которые
 * самостоятельно управляют жизненным циклом message_id.
 *
 * В отличие от GroupedAlertTelegramService этот сервис не перехватывает
 * sendMessage и не превращает его в editMessageText. При этом он сохраняет
 * настройки кнопки карты из модуля публикации тревог.
 */
class DirectGroupChannelTelegramService extends GroupChannelTelegramService
{
    public function request(GroupChannelBot $bot, string $method, array $payload = []): mixed
    {
        if (in_array($method, ['sendMessage', 'editMessageText'], true)) {
            $payload = $this->withConfiguredMapButton($bot, $method, $payload);
        }

        return parent::request($bot, $method, $payload);
    }

    private function withConfiguredMapButton(
        GroupChannelBot $bot,
        string $method,
        array $payload,
    ): array {
        $keyboard = $this->existingInlineKeyboard($payload['reply_markup'] ?? null);
        $enabled = (bool) $bot->moduleSetting(
            GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            'map_button_enabled',
            true,
        );

        if ($enabled) {
            $buttonText = trim((string) $bot->moduleSetting(
                GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
                'map_button_text',
                GroupChannelBot::DEFAULT_ALERT_MAP_BUTTON_TEXT,
            ));
            $buttonUrl = trim((string) $bot->moduleSetting(
                GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
                'map_button_url',
                GroupChannelBot::DEFAULT_ALERT_MAP_BUTTON_URL,
            ));

            if ($buttonText !== '' && filter_var($buttonUrl, FILTER_VALIDATE_URL) !== false) {
                $keyboard[] = [[
                    'text' => $buttonText,
                    'url' => $buttonUrl,
                ]];
            }
        }

        if ($keyboard !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        } elseif ($method === 'editMessageText') {
            $payload['reply_markup'] = ['inline_keyboard' => []];
        } else {
            unset($payload['reply_markup']);
        }

        return $payload;
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    private function existingInlineKeyboard(mixed $replyMarkup): array
    {
        if (is_string($replyMarkup)) {
            $decoded = json_decode($replyMarkup, true);
            $replyMarkup = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($replyMarkup) || ! is_array($replyMarkup['inline_keyboard'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            $replyMarkup['inline_keyboard'],
            fn (mixed $row): bool => is_array($row) && $row !== [],
        ));
    }

}

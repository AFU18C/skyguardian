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
        $enabled = (bool) $bot->moduleSetting(
            GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            'map_button_enabled',
            true,
        );

        if (! $enabled) {
            if ($method === 'editMessageText') {
                $payload['reply_markup'] = ['inline_keyboard' => []];
            } else {
                unset($payload['reply_markup']);
            }

            return $payload;
        }

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

        if ($buttonText === '' || filter_var($buttonUrl, FILTER_VALIDATE_URL) === false) {
            if ($method === 'editMessageText') {
                $payload['reply_markup'] = ['inline_keyboard' => []];
            } else {
                unset($payload['reply_markup']);
            }

            return $payload;
        }

        $payload['reply_markup'] = [
            'inline_keyboard' => [[
                [
                    'text' => $buttonText,
                    'url' => $buttonUrl,
                ],
            ]],
        ];

        return $payload;
    }
}

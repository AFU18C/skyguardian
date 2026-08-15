<?php

namespace App\Services;

use App\Exceptions\TelegramDeliveryUncertainException;
use App\Models\Bet;
use App\Models\GroupChannelBot;
use RuntimeException;

class BetPublicationService
{
    public function __construct(private readonly GroupChannelTelegramService $telegram) {}

    public function publish(Bet $bet, GroupChannelBot $bot): string
    {
        $customText = trim((string) $bet->publication_text);
        $payload = [
            'chat_id' => $this->chat($bot),
            'text' => $customText !== '' ? $customText : $this->betText($bet),
            'disable_web_page_preview' => true,
        ];
        if ($customText === '') {
            $payload['parse_mode'] = 'HTML';
        }
        $result = $this->telegram->request($bot, 'sendMessage', $payload);
        if (empty($result['message_id'])) {
            throw new TelegramDeliveryUncertainException('Telegram принял ставку, но не вернул ID сообщения. Автоматический повтор заблокирован.');
        }

        return (string) $result['message_id'];
    }

    public function sendResult(Bet $bet, GroupChannelBot $bot, ?string $customText = null): string
    {
        $customText = trim((string) $customText);
        $text = $customText !== '' ? $customText : $this->resultText($bet);
        $payload = [
            'chat_id' => $this->chat($bot),
            'text' => $text,
            'disable_web_page_preview' => true,
        ];
        if ($customText === '') {
            $payload['parse_mode'] = 'HTML';
        }
        $result = $this->telegram->request($bot, 'sendMessage', $payload);
        if (empty($result['message_id'])) {
            throw new TelegramDeliveryUncertainException('Telegram принял результат, но не вернул ID сообщения. Автоматический повтор заблокирован.');
        }

        return (string) $result['message_id'];
    }

    public function betText(Bet $bet): string
    {
        $lines = [
            $this->sportIcon($bet->sport).' <b>'.$this->e($bet->event_name).'</b>',
            $bet->tournament ? '🏆 '.$this->e($bet->tournament) : null,
            $bet->starts_at ? '📅 '.$bet->starts_at->timezone(config('app.timezone'))->format('d.m.Y').'  🕘 '.$bet->starts_at->timezone(config('app.timezone'))->format('H:i') : null,
            '',
            '🎯 <b>Прогноз:</b> '.$this->e($bet->market),
            '💰 <b>Коэффициент:</b> '.number_format((float) $bet->selected_odds, 2, '.', ''),
            '📊 <b>Качество данных:</b> '.$bet->ai_score.'%',
            '',
            '⏳ <b>Ожидает результата</b>',
        ];

        return implode("\n", array_filter($lines, fn ($line): bool => $line !== null));
    }

    public function resultText(Bet $bet): string
    {
        $label = match ($bet->result) {
            'win' => '✅ ВЫИГРЫШ',
            'loss' => '❌ ПРОИГРЫШ',
            'refund' => '↩️ ВОЗВРАТ',
            default => '⏳ РЕЗУЛЬТАТ ОЖИДАЕТСЯ',
        };

        return implode("\n", array_filter([
            $this->sportIcon($bet->sport).' <b>'.$this->e($bet->event_name).'</b>',
            '🎯 '.$this->e($bet->market).' · '.number_format((float) $bet->selected_odds, 2, '.', ''),
            '',
            '<b>'.$label.'</b>',
            $bet->result_note ? $this->e($bet->result_note) : null,
        ]));
    }

    private function chat(GroupChannelBot $bot): string
    {
        if ($bot->chat_id) {
            return $bot->chat_id;
        }
        $link = trim((string) $bot->group_link);
        $path = trim((string) parse_url(str_starts_with($link, 'http') ? $link : 'https://'.$link, PHP_URL_PATH), '/');
        if ($path === '') {
            throw new RuntimeException('У канала публикации не указан chat ID или ссылка.');
        }

        return '@'.ltrim(explode('/', $path)[0], '@');
    }

    private function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sportIcon(?string $sport): string
    {
        return match ($sport) {
            'Баскетбол' => '🏀',
            'Хоккей' => '🏒',
            'Теннис' => '🎾',
            'Киберспорт' => '🎮',
            default => '⚽',
        };
    }
}

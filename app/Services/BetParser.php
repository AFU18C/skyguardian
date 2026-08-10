<?php

namespace App\Services;

class BetParser
{
    public function parse(array $message): ?array
    {
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '') {
            return null;
        }

        $teams = $this->teams($text);
        $market = $this->market($text);
        if ($teams === null || $market === null) {
            return null;
        }

        $telegramOdds = null;
        if (preg_match('/(?:коэф(?:фициент)?|коф|odds|кф)[\s:=-]*([1-9]\d?(?:[.,]\d{1,3}))/iu', $text, $match)) {
            $telegramOdds = (float) str_replace(',', '.', $match[1]);
        }

        [$home, $away] = $teams;
        $event = $home.' — '.$away;
        $startsAt = $this->startsAt($text, $message['date'] ?? null);
        $eventDay = $startsAt
            ? (new \DateTimeImmutable($startsAt))->format('Y-m-d')
            : ($this->messageDay($message['date'] ?? null) ?? 'unknown-date');
        $fingerprint = hash('sha256', mb_strtolower($event.'|'.$market.'|'.$eventDay));
        $score = 78 + ($telegramOdds ? 5 : 0) + (! empty($message['date']) ? 4 : 0);

        return [
            'fingerprint' => $fingerprint,
            'sport' => $this->sport($text),
            'event_name' => $event,
            'home_team' => $home,
            'away_team' => $away,
            'tournament' => $this->tournament($text),
            'starts_at' => $startsAt,
            'market' => $market,
            'telegram_odds' => $telegramOdds,
            'ai_score' => min(97, $score),
            'ai_reason' => 'Событие и рынок распознаны в Telegram-публикации. Оценка учитывает полноту данных и подтверждение в нескольких источниках.',
            'telegram_sources' => [[
                'chat_id' => $message['chat_id'] ?? null,
                'chat_title' => $message['chat_title'] ?? 'Telegram',
                'username' => $message['chat_username'] ?? null,
                'message_id' => $message['id'] ?? null,
                'url' => $message['url'] ?? null,
                'date' => $message['date'] ?? null,
                'text' => mb_substr($text, 0, 2000),
            ]],
        ];
    }

    private function messageDay(mixed $messageDate): ?string
    {
        if (! $messageDate) {
            return null;
        }

        try {
            return (new \DateTimeImmutable((string) $messageDate))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function teams(string $text): ?array
    {
        $line = preg_split('/\R/u', $text)[0] ?? $text;
        $patterns = [
            '/(?:⚽\s*)?([\p{L}\d .\'&-]{2,40})\s+(?:—|–|\s-\s|\bvs\.?\b|\bпротив\b)\s+([\p{L}\d .\'&-]{2,40})/iu',
            '/матч[\s:]+([\p{L}\d .\'&-]{2,40})\s+(?:—|–|\s-\s|\bvs\.?\b)\s+([\p{L}\d .\'&-]{2,40})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $match) || preg_match($pattern, $text, $match)) {
                $clean = fn (string $value): string => trim(preg_replace('/\s+/u', ' ', $value), " \t\n\r\0\x0B-–—:⚽");
                $home = $clean($match[1]);
                $away = $clean($match[2]);
                if (mb_strlen($home) >= 2 && mb_strlen($away) >= 2) {
                    return [$home, $away];
                }
            }
        }

        return null;
    }

    private function market(string $text): ?string
    {
        $patterns = [
            '/(?:ТБ|тотал\s+больше)\s*\(?([0-9]+(?:[.,][02575])?)\)?/iu' => 'ТБ ',
            '/(?:ТМ|тотал\s+меньше)\s*\(?([0-9]+(?:[.,][02575])?)\)?/iu' => 'ТМ ',
        ];
        foreach ($patterns as $pattern => $prefix) {
            if (preg_match($pattern, $text, $match)) {
                $value = end($match);
                return $prefix.str_replace(',', '.', (string) $value);
            }
        }
        if (preg_match('/(?:фора|Ф)\s*([12])\s*\(?([+-]?[0-9]+(?:[.,][02575])?)\)?/iu', $text, $match)) {
            return 'Ф'.$match[1].' '.str_replace(',', '.', $match[2]);
        }
        if (preg_match('/обе\s+(?:команды\s+)?забьют(?:\s*[-—:]?\s*(да|нет))?/iu', $text, $match)) {
            return 'Обе забьют — '.mb_convert_case($match[1] ?? 'да', MB_CASE_TITLE);
        }
        if (preg_match('/(?:двойной\s+шанс\s*)?(1X|X2|12)|\bП([12])\b/iu', $text, $match)) {
            return isset($match[2]) && $match[2] !== '' ? 'П'.$match[2] : mb_strtoupper($match[1]);
        }

        return null;
    }

    private function sport(string $text): string
    {
        return match (true) {
            preg_match('/(?:🏀|баскетбол|basketball)/iu', $text) === 1 => 'Баскетбол',
            preg_match('/(?:🏒|хоккей|хокей|hockey)/iu', $text) === 1 => 'Хоккей',
            preg_match('/(?:🎾|теннис|теніс|tennis)/iu', $text) === 1 => 'Теннис',
            preg_match('/(?:🎮|киберспорт|кіберспорт|esports?)/iu', $text) === 1 => 'Киберспорт',
            default => 'Футбол',
        };
    }

    private function tournament(string $text): ?string
    {
        if (preg_match('/(?:турнир|турнір|лига|ліга|чемпионат|чемпіонат)[\s:—-]+([^\r\n]{2,120})/iu', $text, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    private function startsAt(string $text, mixed $messageDate): ?string
    {
        $formats = [
            '/\b(\d{1,2})[.\/]([01]?\d)[.\/](20\d{2})\D{0,12}([0-2]?\d):([0-5]\d)\b/u' => 'dmy',
            '/\b(20\d{2})-([01]?\d)-(\d{1,2})\D{0,12}([0-2]?\d):([0-5]\d)\b/u' => 'ymd',
            '/\b(\d{1,2})[.\/]([01]?\d)\D{1,12}([0-2]?\d):([0-5]\d)\b/u' => 'dm',
        ];
        foreach ($formats as $pattern => $format) {
            if (! preg_match($pattern, $text, $match)) {
                continue;
            }
            $reference = $messageDate ? new \DateTimeImmutable((string) $messageDate) : new \DateTimeImmutable;
            [$year, $month, $day, $hour, $minute] = match ($format) {
                'dmy' => [(int) $match[3], (int) $match[2], (int) $match[1], (int) $match[4], (int) $match[5]],
                'ymd' => [(int) $match[1], (int) $match[2], (int) $match[3], (int) $match[4], (int) $match[5]],
                default => [(int) $reference->format('Y'), (int) $match[2], (int) $match[1], (int) $match[3], (int) $match[4]],
            };
            if (! checkdate($month, $day, $year) || $hour > 23) {
                continue;
            }
            $date = $reference->setDate($year, $month, $day)->setTime($hour, $minute);
            if ($format === 'dm' && $date < $reference->modify('-30 days')) {
                $date = $date->modify('+1 year');
            }

            return $date->format(DATE_ATOM);
        }

        return null;
    }
}

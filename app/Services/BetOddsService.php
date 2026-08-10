<?php

namespace App\Services;

use App\Models\BettingSetting;
use Illuminate\Support\Facades\Http;

class BetOddsService
{
    /** @var array<string, array{body:?string,http_status:?int,error:?string}> */
    private array $responses = [];

    public function lookup(array $bet, BettingSetting $settings): array
    {
        $primary = $this->inspect($settings->primary_source_url, $bet);
        $reserve = $this->inspect($settings->reserve_source_url, $bet);
        $primaryOdds = $primary['odds'];
        $reserveOdds = $reserve['odds'];

        return [
            'primary_odds' => $primaryOdds,
            'reserve_odds' => $reserveOdds,
            'selected_odds' => $primaryOdds ?? $reserveOdds,
            'selected_odds_source' => $primaryOdds !== null
                ? $settings->primary_source_name
                : ($reserveOdds !== null ? $settings->reserve_source_name : null),
            'tournament' => $primary['tournament'] ?? $reserve['tournament'] ?? ($bet['tournament'] ?? null),
            'starts_at' => $primary['starts_at'] ?? $reserve['starts_at'] ?? ($bet['starts_at'] ?? null),
            'external_event_id' => $primary['event_id'] ?? $reserve['event_id'] ?? null,
            'odds_snapshot' => [
                'telegram' => $bet['telegram_odds'] ?? null,
                'primary' => array_merge([
                    'name' => $settings->primary_source_name,
                    'url' => $settings->primary_source_url,
                ], $primary),
                'reserve' => array_merge([
                    'name' => $settings->reserve_source_name,
                    'url' => $settings->reserve_source_url,
                ], $reserve),
            ],
            'odds_checked_at' => now(),
        ];
    }

    public function inspect(?string $url, array $bet): array
    {
        $empty = [
            'odds' => null,
            'event_found' => false,
            'event_id' => null,
            'tournament' => null,
            'starts_at' => null,
            'score' => null,
            'finished' => false,
            'http_status' => null,
            'error' => null,
        ];

        if (! $url) {
            return $empty;
        }

        $source = $this->fetch($url);
        if ($source['body'] === null) {
            return array_replace($empty, [
                'http_status' => $source['http_status'],
                'error' => $source['error'],
            ]);
        }

        if ($this->accessBlocked($source['body'])) {
            return array_replace($empty, [
                'http_status' => $source['http_status'],
                'error' => 'Источник ограничил доступ с сервера. Используйте резервный источник.',
            ]);
        }

        return array_replace($empty, $this->extract($source['body'], $bet), [
            'http_status' => $source['http_status'],
        ]);
    }

    private function accessBlocked(string $body): bool
    {
        return preg_match('/<title>\s*Beton Error\s*<\/title>|Немає доступу\?|access denied|forbidden/iu', $body) === 1;
    }

    /** @return array{body:?string,http_status:?int,error:?string} */
    private function fetch(string $url): array
    {
        if (array_key_exists($url, $this->responses)) {
            return $this->responses[$url];
        }

        try {
            $response = Http::timeout(20)->retry(1, 250, throw: false)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; SkyGuardian/1.0)',
                'Accept' => 'text/html,application/json;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'uk,ru;q=0.9,en;q=0.8',
            ])->get($url);

            return $this->responses[$url] = $response->successful()
                ? ['body' => $response->body(), 'http_status' => $response->status(), 'error' => null]
                : ['body' => null, 'http_status' => $response->status(), 'error' => 'Источник вернул HTTP '.$response->status().'.'];
        } catch (\Throwable $e) {
            return $this->responses[$url] = [
                'body' => null,
                'http_status' => null,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ];
        }
    }

    /** @return array<string, mixed> */
    public function extract(string $body, array $bet): array
    {
        $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body))) ?? '';
        $window = $this->eventWindow($text, (string) ($bet['home_team'] ?? ''), (string) ($bet['away_team'] ?? ''));
        if ($window === null) {
            return [];
        }

        $odds = null;
        foreach ($this->marketLabels((string) ($bet['market'] ?? '')) as $label) {
            $quoted = preg_quote($label, '/');
            $quoted = str_replace(['\\ ', '\\.'], ['\\s*', '[.,]'], $quoted);
            if (preg_match('/'.$quoted.'.{0,100}?([1-9]\d?[.,]\d{1,3})/iu', $window, $match)) {
                $odds = (float) str_replace(',', '.', $match[1]);
                break;
            }
        }

        $finished = preg_match('/(?:завершен(?:о|ий)?|окончен(?:о)?|finished|full\s*time|\bFT\b|final)/iu', $window) === 1;
        $score = $finished ? $this->score($window, (string) $bet['home_team'], (string) $bet['away_team']) : null;

        preg_match('/(?:event[_-]?id|data-event-id)["\s:=]+([A-Za-z0-9_-]{3,100})/iu', $window, $eventMatch);
        preg_match('/(?:tournament|league)(?:Name|_name|-name)?["\s:=]+["\']?([^"\'\}\],]{2,120})/iu', $window, $tournamentMatch);
        preg_match('/\b(20\d{2}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\b/u', $window, $startsMatch);

        return [
            'event_found' => true,
            'odds' => $odds,
            'event_id' => $eventMatch[1] ?? null,
            'tournament' => isset($tournamentMatch[1]) ? trim($tournamentMatch[1]) : null,
            'starts_at' => $startsMatch[1] ?? null,
            'score' => $score,
            'finished' => $finished,
        ];
    }

    private function eventWindow(string $text, string $home, string $away): ?string
    {
        if ($home === '' || $away === '') {
            return null;
        }
        $homePosition = mb_stripos($text, $home);
        $awayPosition = mb_stripos($text, $away);
        if ($homePosition === false || $awayPosition === false || abs($homePosition - $awayPosition) > 5000) {
            return null;
        }

        $start = max(0, min($homePosition, $awayPosition) - 700);

        return mb_substr($text, $start, 7000);
    }

    /** @return list<string> */
    private function marketLabels(string $market): array
    {
        if (preg_match('/^ТБ\s*([0-9.]+)/u', $market, $match)) {
            return [$market, 'Тотал больше '.$match[1], 'Тотал більше '.$match[1], 'Over '.$match[1]];
        }
        if (preg_match('/^ТМ\s*([0-9.]+)/u', $market, $match)) {
            return [$market, 'Тотал меньше '.$match[1], 'Тотал менше '.$match[1], 'Under '.$match[1]];
        }
        if (str_starts_with($market, 'Обе забьют')) {
            $wanted = str_contains(mb_strtolower($market), 'нет') ? 'Нет' : 'Да';

            return [$market, 'Обе забьют '.$wanted, "Обидві заб'ють ".$wanted, 'Both teams to score '.$wanted];
        }
        if (preg_match('/^Ф([12])\s*([+-]?[0-9.]+)/u', $market, $match)) {
            return [$market, 'Фора '.$match[1].' '.$match[2], 'Handicap '.$match[1].' '.$match[2]];
        }

        return match ($market) {
            'П1' => ['П1', 'Победа 1', 'Перемога 1', 'Home win'],
            'П2' => ['П2', 'Победа 2', 'Перемога 2', 'Away win'],
            '1X' => ['1X', '1Х', 'Победа 1 или ничья', 'Перемога 1 або нічия'],
            'X2' => ['X2', 'Х2', 'Победа 2 или ничья', 'Перемога 2 або нічия'],
            default => [$market],
        };
    }

    /** @return array{0:int, 1:int}|null */
    private function score(string $window, string $home, string $away): ?array
    {
        $home = preg_quote($home, '/');
        $away = preg_quote($away, '/');
        foreach ([
            '/'.$home.'.{0,250}?(\d{1,2})\s*[:\-]\s*(\d{1,2}).{0,250}?'.$away.'/isu',
            '/'.$away.'.{0,250}?(\d{1,2})\s*[:\-]\s*(\d{1,2}).{0,250}?'.$home.'/isu',
        ] as $index => $pattern) {
            if (preg_match($pattern, $window, $match)) {
                return $index === 0
                    ? [(int) $match[1], (int) $match[2]]
                    : [(int) $match[2], (int) $match[1]];
            }
        }

        return null;
    }
}

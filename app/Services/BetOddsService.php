<?php

namespace App\Services;

use App\Models\BettingSetting;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class BetOddsService
{
    /** @var array<string, array{body:?string,http_status:?int,error:?string}> */
    private array $responses = [];

    private readonly PublicUrlGuard $urlGuard;

    public function __construct(?PublicUrlGuard $urlGuard = null)
    {
        $this->urlGuard = $urlGuard ?? new PublicUrlGuard;
    }

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
        $empty = $this->emptyResult();
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

        $extracted = $this->extract($source['body'], $bet);
        if ($extracted === []) {
            $extracted['error'] = 'Источник не предоставил структурированные данные для точного сопоставления события.';
        }

        return array_replace($empty, $extracted, ['http_status' => $source['http_status']]);
    }

    /** @return array<string, mixed> */
    public function extract(string $body, array $bet): array
    {
        $home = trim((string) ($bet['home_team'] ?? ''));
        $away = trim((string) ($bet['away_team'] ?? ''));
        if ($home === '' || $away === '') {
            return [];
        }

        $matches = [];
        foreach ($this->jsonDocuments($body) as $document) {
            foreach ($this->objects($document) as $object) {
                if ($this->matchesTeams($object, $home, $away)) {
                    $identity = $this->stringOrNull($this->first($object, [
                        'event_id', 'eventId', 'id', 'matchId',
                    ]));
                    $key = $identity !== null
                        ? 'id:'.$identity
                        : 'object:'.hash('sha256', json_encode($object, JSON_THROW_ON_ERROR));
                    $matches[$key] = $object;
                }
            }
        }

        $matches = $this->narrowMatches(array_values($matches), $bet);
        if (count($matches) !== 1) {
            return [];
        }

        $object = $matches[0];
        $status = mb_strtolower(trim((string) $this->first($object, [
            'status', 'state', 'eventStatus', 'match_status',
        ])));
        $finished = in_array($status, [
            'finished', 'final', 'full_time', 'full time', 'ended', 'complete', 'completed', 'ft',
        ], true);
        $score = $finished ? $this->structuredScore($object) : null;

        return [
            'event_found' => true,
            'odds' => $this->structuredOdds($object, (string) ($bet['market'] ?? '')),
            'event_id' => $this->stringOrNull($this->first($object, ['event_id', 'eventId', 'id', 'matchId'])),
            'tournament' => $this->nestedString($object, ['tournament', 'league', 'leagueName', 'competition']),
            'starts_at' => $this->stringOrNull($this->first($object, [
                'starts_at', 'startsAt', 'startTime', 'start_time', 'date', 'scheduled',
            ])),
            'score' => $score,
            'finished' => $finished && $score !== null,
            'error' => null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @param  array<string, mixed>  $bet
     * @return array<int, array<string, mixed>>
     */
    private function narrowMatches(array $matches, array $bet): array
    {
        $externalId = $this->stringOrNull($bet['external_event_id'] ?? null);
        if ($externalId !== null) {
            $matches = array_values(array_filter(
                $matches,
                fn (array $object): bool => $this->stringOrNull($this->first(
                    $object,
                    ['event_id', 'eventId', 'id', 'matchId'],
                )) === $externalId,
            ));
        }

        $startsAt = $this->stringOrNull($bet['starts_at'] ?? null);
        if ($startsAt !== null) {
            $hasDatedMatch = false;
            $dated = array_values(array_filter($matches, function (array $object) use ($startsAt, &$hasDatedMatch): bool {
                $candidate = $this->stringOrNull($this->first($object, [
                    'starts_at', 'startsAt', 'startTime', 'start_time', 'date', 'scheduled',
                ]));
                $hasDatedMatch = $hasDatedMatch || $candidate !== null;

                return $candidate !== null && $this->sameInstant($candidate, $startsAt);
            }));
            if ($hasDatedMatch) {
                $matches = $dated;
            }
        }

        $tournament = $this->stringOrNull($bet['tournament'] ?? null);
        if ($tournament !== null) {
            $hasTournamentMatch = false;
            $tournaments = array_values(array_filter($matches, function (array $object) use ($tournament, &$hasTournamentMatch): bool {
                $candidate = $this->nestedString(
                    $object,
                    ['tournament', 'league', 'leagueName', 'competition'],
                );
                $hasTournamentMatch = $hasTournamentMatch || $candidate !== null;

                return $candidate !== null
                    && $this->normalize($candidate) === $this->normalize($tournament);
            }));
            if ($hasTournamentMatch) {
                $matches = $tournaments;
            }
        }

        return $matches;
    }

    private function sameInstant(string $left, string $right): bool
    {
        try {
            return abs(
                CarbonImmutable::parse($left)->getTimestamp()
                - CarbonImmutable::parse($right)->getTimestamp(),
            ) <= 300;
        } catch (\Throwable) {
            return false;
        }
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

        $current = $url;
        try {
            for ($redirect = 0; $redirect <= 3; $redirect++) {
                $target = $this->urlGuard->inspect($current);
                if (! defined('CURLOPT_RESOLVE')) {
                    throw new \RuntimeException('Безопасная загрузка внешнего источника требует PHP cURL.');
                }
                $request = Http::connectTimeout(4)
                    ->timeout(12)
                    ->withoutRedirecting()
                    ->withHeaders([
                        'User-Agent' => 'SkyGuardian/1.0 (+structured odds lookup)',
                        'Accept' => 'application/json,text/html;q=0.8,*/*;q=0.5',
                        'Accept-Language' => 'uk,ru;q=0.9,en;q=0.8',
                    ]);

                $pinnedIp = str_contains($target['ips'][0], ':')
                    ? '['.$target['ips'][0].']'
                    : $target['ips'][0];
                $curlOptions = [];
                if (filter_var($target['host'], FILTER_VALIDATE_IP) === false) {
                    $curlOptions[constant('CURLOPT_RESOLVE')] = [
                        $target['host'].':'.$target['port'].':'.$pinnedIp,
                    ];
                }
                if (defined('CURLOPT_NOPROGRESS') && defined('CURLOPT_XFERINFOFUNCTION')) {
                    $curlOptions[constant('CURLOPT_NOPROGRESS')] = false;
                    $curlOptions[constant('CURLOPT_XFERINFOFUNCTION')] = static function (
                        mixed $handle,
                        mixed $downloadTotal,
                        mixed $downloaded,
                    ): int {
                        return (int) $downloaded > 2_000_000 || (int) $downloadTotal > 2_000_000 ? 1 : 0;
                    };
                }
                $request = $request->withOptions([
                    'curl' => $curlOptions,
                ]);

                $response = $request->get($current);
                if ($response->redirect()) {
                    $location = $response->header('Location');
                    if (! is_string($location) || trim($location) === '') {
                        break;
                    }
                    $current = $this->redirectUrl($current, $location);

                    continue;
                }

                if (! $response->successful()) {
                    return $this->responses[$url] = [
                        'body' => null,
                        'http_status' => $response->status(),
                        'error' => 'Источник вернул HTTP '.$response->status().'.',
                    ];
                }

                $body = $response->body();
                if (strlen($body) > 2_000_000) {
                    return $this->responses[$url] = [
                        'body' => null,
                        'http_status' => $response->status(),
                        'error' => 'Ответ источника превышает допустимые 2 МБ.',
                    ];
                }

                return $this->responses[$url] = [
                    'body' => $body,
                    'http_status' => $response->status(),
                    'error' => null,
                ];
            }

            return $this->responses[$url] = [
                'body' => null,
                'http_status' => null,
                'error' => 'Источник выполнил слишком много перенаправлений.',
            ];
        } catch (ConnectionException $e) {
            return $this->responses[$url] = [
                'body' => null,
                'http_status' => null,
                'error' => 'Не удалось подключиться к источнику: '.mb_substr($e->getMessage(), 0, 300),
            ];
        } catch (\Throwable $e) {
            return $this->responses[$url] = [
                'body' => null,
                'http_status' => null,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ];
        }
    }

    /** @return array<int, mixed> */
    private function jsonDocuments(string $body): array
    {
        $documents = [];
        $trimmed = trim($body);
        if ($trimmed !== '' && in_array($trimmed[0], ['{', '['], true)) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $documents[] = $decoded;
            }
        }

        if (preg_match_all('~<script\b[^>]*type=["\']application/(?:ld\+)?json["\'][^>]*>(.*?)</script>~isu', $body, $matches)) {
            foreach ($matches[1] as $json) {
                $decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                if (is_array($decoded)) {
                    $documents[] = $decoded;
                }
            }
        }

        return $documents;
    }

    /** @return array<int, array<string, mixed>> */
    private function objects(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $objects = array_is_list($value) ? [] : [$value];
        foreach ($value as $item) {
            if (is_array($item)) {
                $objects = array_merge($objects, $this->objects($item));
            }
        }

        return $objects;
    }

    private function matchesTeams(array $object, string $home, string $away): bool
    {
        $candidateHome = $this->nestedString($object, ['home_team', 'homeTeam', 'home', 'team1']);
        $candidateAway = $this->nestedString($object, ['away_team', 'awayTeam', 'away', 'team2']);

        return $candidateHome !== null
            && $candidateAway !== null
            && $this->normalize($candidateHome) === $this->normalize($home)
            && $this->normalize($candidateAway) === $this->normalize($away);
    }

    private function structuredOdds(array $object, string $market): ?float
    {
        $wanted = collect($this->marketLabels($market))->map($this->normalize(...))->all();
        foreach ($this->objects($object['markets'] ?? $object['odds'] ?? []) as $candidate) {
            $label = $this->nestedString($candidate, ['market', 'label', 'name', 'title', 'selection']);
            $value = $this->first($candidate, ['odds', 'price', 'value', 'coefficient']);
            if ($label !== null && is_numeric($value) && in_array($this->normalize($label), $wanted, true)) {
                $odds = (float) $value;

                return $odds > 1 && $odds < 10000 ? $odds : null;
            }
        }

        return null;
    }

    /** @return array{0:int,1:int}|null */
    private function structuredScore(array $object): ?array
    {
        $score = $object['score'] ?? $object['result'] ?? null;
        $home = is_array($score) ? $this->first($score, ['home', 'homeScore', 'team1']) : null;
        $away = is_array($score) ? $this->first($score, ['away', 'awayScore', 'team2']) : null;
        $home ??= $this->first($object, ['home_score', 'homeScore']);
        $away ??= $this->first($object, ['away_score', 'awayScore']);

        return is_numeric($home) && is_numeric($away)
            ? [(int) $home, (int) $away]
            : null;
    }

    private function nestedString(array $object, array $keys): ?string
    {
        $value = $this->first($object, $keys);
        if (is_array($value)) {
            $value = $this->first($value, ['name', 'title', 'label']);
        }

        return $this->stringOrNull($value);
    }

    private function first(array $object, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $object)) {
                return $object[$key];
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}+.\-]+/u', ' ', $value) ?? $value));
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

    private function redirectUrl(string $base, string $location): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL) !== false) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $directory = preg_replace('~/[^/]*$~', '/', (string) ($parts['path'] ?? '/')) ?: '/';

        return $origin.$directory.$location;
    }

    /** @return array<string,mixed> */
    private function emptyResult(): array
    {
        return [
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
    }
}

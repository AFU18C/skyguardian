<?php

namespace App\Services;

use App\Models\Bet;
use App\Models\BetSearchRun;
use App\Models\BettingSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class BetSearchService
{
    public function __construct(
        private readonly TelethonClient $telethon,
        private readonly BetParser $parser,
        private readonly BetOddsService $odds,
        private readonly WebsiteBetSearchService $websites,
    ) {}

    public function queue(BettingSetting $settings, string $mode, ?int $requestedByUserId = null): BetSearchRun
    {
        $this->assertConfiguration($settings, $mode);

        $active = BetSearchRun::query()
            ->whereIn('status', [BetSearchRun::STATUS_PENDING, BetSearchRun::STATUS_RUNNING])
            ->where('created_at', '>=', now()->subHour())
            ->oldest()
            ->first();

        if ($active) {
            throw new RuntimeException('Поиск ставок уже поставлен в очередь или выполняется.');
        }

        return BetSearchRun::query()->create([
            'status' => BetSearchRun::STATUS_PENDING,
            'search_mode' => $mode,
            'requested_by_user_id' => $requestedByUserId,
        ]);
    }

    /**
     * Synchronous entry point kept for service-level checks and tests.
     */
    public function run(BettingSetting $settings, string $mode = 'telegram'): BetSearchRun
    {
        $this->assertConfiguration($settings, $mode);

        $run = BetSearchRun::query()->create([
            'status' => BetSearchRun::STATUS_RUNNING,
            'search_mode' => $mode,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        return $this->execute($settings, $run);
    }

    public function runQueued(BetSearchRun $run): BetSearchRun
    {
        $claimed = DB::transaction(function () use ($run): ?BetSearchRun {
            $locked = BetSearchRun::query()->lockForUpdate()->find($run->id);
            if (! $locked) {
                return null;
            }

            $isPending = $locked->status === BetSearchRun::STATUS_PENDING;
            $isStale = $locked->status === BetSearchRun::STATUS_RUNNING
                && $locked->started_at?->lte(now()->subMinutes(30));

            if (! $isPending && ! $isStale) {
                return null;
            }

            $locked->update([
                'status' => BetSearchRun::STATUS_RUNNING,
                'attempts' => $locked->attempts + 1,
                'started_at' => now(),
                'finished_at' => null,
                'last_error' => null,
            ]);

            return $locked->fresh();
        });

        if (! $claimed) {
            return $run->fresh();
        }

        $settings = BettingSetting::current();
        $this->assertConfiguration($settings, $claimed->search_mode);

        return $this->execute($settings, $claimed);
    }

    private function execute(BettingSetting $settings, BetSearchRun $run): BetSearchRun
    {
        $mode = $run->search_mode;
        $channels = array_values(array_filter(
            $settings->telegram_channels ?? [],
            fn (mixed $channel): bool => is_string($channel) && trim($channel) !== '',
        ));
        $websiteSources = array_values(array_filter(
            $settings->website_sources ?? [],
            fn (mixed $source): bool => is_array($source)
                && ($source['enabled'] ?? true) === true
                && ! empty($source['url']),
        ));
        $searchTelegram = in_array($mode, ['telegram', 'all'], true);
        $searchWebsites = in_array($mode, ['websites', 'all'], true);
        $account = $searchTelegram ? $settings->technicalAccount : null;

        $lock = Cache::lock('betting:manual-search', 1800);
        if (! $lock->get()) {
            $run->update([
                'status' => BetSearchRun::STATUS_ERROR,
                'last_error' => 'Поиск уже выполняется.',
                'finished_at' => now(),
            ]);
            throw new RuntimeException('Поиск ставок уже выполняется. Дождитесь его завершения.');
        }

        try {
            $limit = min(500, max(50, $settings->maximum_results * 20));
            $response = ['messages' => [], 'channel_errors' => [], 'source_errors' => []];
            $telegramResponse = [];

            if ($searchTelegram) {
                $telegramResponse = $this->telethon->call('search_bets', $account, [
                    'keywords' => $settings->keywords,
                    'channels' => $channels,
                    'freshness_hours' => $settings->freshness_hours,
                    'limit' => $limit,
                ]);
                $response['messages'] = array_merge($response['messages'], $telegramResponse['messages'] ?? []);
                $response['channel_errors'] = $telegramResponse['channel_errors'] ?? [];
            }

            if ($searchWebsites) {
                $websiteResponse = $this->websites->search($websiteSources, $settings->keywords ?? [], $limit);
                $response['messages'] = array_merge($response['messages'], $websiteResponse['messages']);
                $response['source_errors'] = $websiteResponse['source_errors'];
            }

            if ($searchTelegram && ! empty($telegramResponse['session']) && $account) {
                $account->update(['session' => $telegramResponse['session'], 'last_success_at' => now()]);
            }

            $grouped = [];
            foreach ($response['messages'] ?? [] as $message) {
                $parsed = $this->parser->parse($message);
                if (! $parsed || Bet::query()->where('fingerprint', $parsed['fingerprint'])->where('status', Bet::STATUS_PUBLISHED)->exists()) {
                    continue;
                }
                $fingerprint = $parsed['fingerprint'];
                if (isset($grouped[$fingerprint])) {
                    $grouped[$fingerprint]['telegram_sources'] = array_merge($grouped[$fingerprint]['telegram_sources'], $parsed['telegram_sources']);
                    $grouped[$fingerprint]['search_sources'] = array_merge($grouped[$fingerprint]['search_sources'], $parsed['search_sources']);
                    $grouped[$fingerprint]['ai_score'] = min(97, $grouped[$fingerprint]['ai_score'] + 6);
                } else {
                    $grouped[$fingerprint] = $parsed;
                }
            }

            uasort($grouped, fn (array $a, array $b): int => $b['ai_score'] <=> $a['ai_score']);
            $accepted = array_slice(array_filter(
                $grouped,
                fn (array $bet): bool => $bet['ai_score'] >= $settings->minimum_ai_score,
            ), 0, $settings->maximum_results, true);

            $prepared = [];
            foreach ($accepted as $data) {
                $verified = array_merge($data, $this->odds->lookup($data, $settings));

                if (! $this->isVerifiedAvailableBet($verified, $settings)) {
                    continue;
                }

                $prepared[] = $verified;
            }

            $discardedCount = count($accepted) - count($prepared);

            DB::transaction(function () use ($prepared): void {
                foreach ($prepared as $data) {
                    $existing = Bet::query()
                        ->where('fingerprint', $data['fingerprint'])
                        ->where('status', Bet::STATUS_FOUND)
                        ->lockForUpdate()
                        ->first();
                    if (! $existing) {
                        Bet::query()->create(array_merge($data, ['status' => Bet::STATUS_FOUND]));

                        continue;
                    }

                    $data['telegram_sources'] = collect(array_merge(
                        $existing->telegram_sources ?? [],
                        $data['telegram_sources'] ?? [],
                    ))->unique(fn (array $source): string => ($source['chat_id'] ?? 'unknown').':'.($source['message_id'] ?? 'unknown'))->values()->all();
                    $data['search_sources'] = collect(array_merge(
                        $existing->search_sources ?? [],
                        $data['search_sources'] ?? [],
                    ))->unique(fn (array $source): string => ($source['type'] ?? 'unknown').':'.($source['url'] ?? '').':'.($source['message_id'] ?? ''))->values()->all();
                    $existing->update($data);
                }
            });

            $channelErrors = collect($response['channel_errors'] ?? [])
                ->pluck('channel')
                ->filter()
                ->values();
            $websiteErrors = collect($response['source_errors'] ?? [])
                ->map(fn (array $error): string => ($error['source'] ?? 'Сайт').': '.($error['error'] ?? 'ошибка'))
                ->filter()
                ->values();
            $errors = collect();
            if ($channelErrors->isNotEmpty()) {
                $errors->push('Не удалось проверить Telegram-каналы: '.$channelErrors->join(', ').'.');
            }
            if ($websiteErrors->isNotEmpty()) {
                $errors->push('Не удалось проверить сайты: '.$websiteErrors->join('; ').'.');
            }
            if ($discardedCount > 0) {
                $errors->push("Отклонено отсутствующих или завершённых ставок: {$discardedCount}.");
            }
            $run->update([
                'status' => BetSearchRun::STATUS_COMPLETED,
                'messages_found' => count($response['messages'] ?? []),
                'bets_found' => count($prepared),
                'last_error' => $errors->isEmpty() ? null : $errors->join(' '),
                'finished_at' => now(),
            ]);
            $this->cleanup($settings);

            return $run->fresh();
        } catch (Throwable $e) {
            $run->update([
                'status' => BetSearchRun::STATUS_ERROR,
                'last_error' => mb_substr($e->getMessage(), 0, 4000),
                'finished_at' => now(),
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function assertConfiguration(BettingSetting $settings, string $mode): void
    {
        if (! in_array($mode, ['telegram', 'websites', 'all'], true)) {
            throw new RuntimeException('Неизвестный источник поиска.');
        }

        $searchTelegram = in_array($mode, ['telegram', 'all'], true);
        $searchWebsites = in_array($mode, ['websites', 'all'], true);
        $channels = array_values(array_filter(
            $settings->telegram_channels ?? [],
            fn (mixed $channel): bool => is_string($channel) && trim($channel) !== '',
        ));
        $websiteSources = array_values(array_filter(
            $settings->website_sources ?? [],
            fn (mixed $source): bool => is_array($source)
                && ($source['enabled'] ?? true) === true
                && ! empty($source['url']),
        ));

        if ($searchTelegram && $channels === []) {
            throw new RuntimeException('Добавьте хотя бы один Telegram-канал в подразделе «Telegram-источники».');
        }
        if ($searchWebsites && $websiteSources === []) {
            throw new RuntimeException('Добавьте хотя бы один сайт в подразделе «Сайты-источники».');
        }

        $account = $searchTelegram ? $settings->technicalAccount : null;
        if ($searchTelegram && (! $account || ! $account->is_active || $account->status !== 'connected')) {
            throw new RuntimeException('Выберите подключённый технический аккаунт для поиска ставок.');
        }
    }

    public function cleanup(BettingSetting $settings): void
    {
        Bet::query()
            ->where('status', Bet::STATUS_FOUND)
            ->get()
            ->reject(fn (Bet $bet): bool => $this->isVerifiedAvailableBet($bet->toArray(), $settings))
            ->each->delete();

        Bet::query()
            ->where('status', Bet::STATUS_PUBLISHING)
            ->where('updated_at', '<', now()->subMinutes(15))
            ->get()
            ->each->update(['status' => Bet::STATUS_FOUND]);
        Bet::query()->where('status', Bet::STATUS_FOUND)->where('updated_at', '<', now()->subDays($settings->found_retention_days))->delete();
        Bet::query()->where('status', Bet::STATUS_REJECTED)->where('updated_at', '<', now()->subDays($settings->rejected_retention_days))->delete();
        if ($settings->completed_retention_days) {
            Bet::query()
                ->where('status', Bet::STATUS_PUBLISHED)
                ->whereIn('result', ['win', 'loss', 'refund'])
                ->where('result_checked_at', '<', now()->subDays($settings->completed_retention_days))
                ->delete();
        }
        BetSearchRun::query()
            ->whereIn('status', [BetSearchRun::STATUS_COMPLETED, BetSearchRun::STATUS_ERROR])
            ->where('created_at', '<', now()->subDays(30))
            ->delete();
    }

    public function revalidateFoundBets(BettingSetting $settings): void
    {
        Bet::query()
            ->where('status', Bet::STATUS_FOUND)
            ->where(fn ($query) => $query
                ->whereNull('odds_checked_at')
                ->orWhere('odds_checked_at', '<=', now()->subMinutes(5)))
            ->oldest('odds_checked_at')
            ->limit(10)
            ->get()
            ->each(function (Bet $bet) use ($settings): void {
                $odds = $this->odds->lookup($bet->toArray(), $settings);
                $verified = array_merge($bet->toArray(), $odds);

                if ($this->isVerifiedAvailableBet($verified, $settings)) {
                    $bet->update($odds);

                    return;
                }

                if ($this->isDefinitivelyUnavailable($odds, $settings)) {
                    $bet->delete();

                    return;
                }

                $bet->update(['odds_checked_at' => now()]);
            });
    }

    /** @param  array<string, mixed>  $bet */
    private function isVerifiedAvailableBet(array $bet, BettingSetting $settings): bool
    {
        foreach ($this->verificationSourceKeys($settings) as $sourceKey) {
            $source = data_get($bet, "odds_snapshot.{$sourceKey}");

            if (! is_array($source)
                || ($source['event_found'] ?? false) !== true
                || ($source['finished'] ?? false) === true
                || ! is_numeric($source['odds'] ?? null)
                || (float) $source['odds'] <= 1) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** @param  array<string, mixed>  $odds */
    private function isDefinitivelyUnavailable(array $odds, BettingSetting $settings): bool
    {
        foreach ($this->verificationSourceKeys($settings) as $sourceKey) {
            $source = data_get($odds, "odds_snapshot.{$sourceKey}");
            if (! is_array($source)) {
                continue;
            }

            if (($source['event_found'] ?? false) === true && ($source['finished'] ?? false) === true) {
                return true;
            }

            if (($source['event_found'] ?? false) === false
                && str_contains((string) ($source['error'] ?? ''), 'актуальной линии BETON')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function verificationSourceKeys(BettingSetting $settings): array
    {
        return match (true) {
            $this->isBetonUrl($settings->primary_source_url) => ['primary'],
            $this->isBetonUrl($settings->reserve_source_url) => ['reserve'],
            default => ['primary', 'reserve'],
        };
    }

    private function isBetonUrl(?string $url): bool
    {
        $host = mb_strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        return $host === 'beton.ua' || str_ends_with($host, '.beton.ua');
    }
}

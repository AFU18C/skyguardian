<?php

namespace App\Services;

use App\Models\Bet;
use App\Models\BetSearchRun;
use App\Models\BettingSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BetSearchService
{
    public function __construct(
        private readonly TelethonClient $telethon,
        private readonly BetParser $parser,
        private readonly BetOddsService $odds,
        private readonly WebsiteBetSearchService $websites,
    ) {}

    public function validateConfiguration(BettingSetting $settings, string $mode): void
    {
        if (! in_array($mode, ['telegram', 'websites', 'all'], true)) {
            throw new RuntimeException('Неизвестный источник поиска.');
        }

        $channels = array_values(array_filter($settings->telegram_channels ?? [], fn (mixed $channel): bool => is_string($channel) && trim($channel) !== ''));
        $websiteSources = array_values(array_filter($settings->website_sources ?? [], fn (mixed $source): bool => is_array($source) && ($source['enabled'] ?? true) === true && ! empty($source['url'])));
        $searchTelegram = in_array($mode, ['telegram', 'all'], true);
        $searchWebsites = in_array($mode, ['websites', 'all'], true);

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

    public function run(BettingSetting $settings, string $mode = 'telegram', ?BetSearchRun $run = null): BetSearchRun
    {
        $this->validateConfiguration($settings, $mode);
        $run ??= BetSearchRun::query()->create(['status' => 'queued', 'search_mode' => $mode]);
        $run->update([
            'status' => 'running',
            'started_at' => now(),
            'progress_percent' => 5,
            'status_message' => 'Подготовка источников',
            'last_error' => null,
            'finished_at' => null,
        ]);

        $channels = array_values(array_filter($settings->telegram_channels ?? [], fn (mixed $channel): bool => is_string($channel) && trim($channel) !== ''));
        $websiteSources = array_values(array_filter($settings->website_sources ?? [], fn (mixed $source): bool => is_array($source) && ($source['enabled'] ?? true) === true && ! empty($source['url'])));
        $searchTelegram = in_array($mode, ['telegram', 'all'], true);
        $searchWebsites = in_array($mode, ['websites', 'all'], true);
        $account = $searchTelegram ? $settings->technicalAccount : null;

        $lock = Cache::lock('betting:manual-search', 600);
        if (! $lock->get()) {
            $run->update(['status' => 'error', 'last_error' => 'Поиск уже выполняется.', 'finished_at' => now()]);
            throw new RuntimeException('Поиск ставок уже выполняется. Дождитесь его завершения.');
        }

        try {
            $limit = min(500, max(50, $settings->maximum_results * 20));
            $response = ['messages' => [], 'channel_errors' => [], 'source_errors' => []];

            if ($searchTelegram) {
                $run->update(['progress_percent' => 15, 'status_message' => 'Поиск в Telegram']);
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
                $run->update(['progress_percent' => $searchTelegram ? 45 : 15, 'status_message' => 'Проверка сайтов в браузере']);
                $websiteResponse = $this->websites->search(
                    $websiteSources,
                    $settings->keywords ?? [],
                    $limit,
                    $settings->freshness_hours,
                );
                $response['messages'] = array_merge($response['messages'], $websiteResponse['messages']);
                $response['source_errors'] = $websiteResponse['source_errors'];
            }

            if ($searchTelegram && ! empty($telegramResponse['session'])) {
                $account->update(['session' => $telegramResponse['session'], 'last_success_at' => now()]);
            }

            $run->update(['progress_percent' => 70, 'status_message' => 'Проверка и объединение найденных прогнозов']);
            $grouped = [];
            foreach ($response['messages'] ?? [] as $message) {
                $parsed = $this->parser->parse($message);
                if (! $parsed || Bet::query()->where('publication_guard', $parsed['fingerprint'])->exists()) {
                    continue;
                }
                $fingerprint = $parsed['fingerprint'];
                if (isset($grouped[$fingerprint])) {
                    $grouped[$fingerprint]['telegram_sources'] = array_merge($grouped[$fingerprint]['telegram_sources'], $parsed['telegram_sources']);
                    $grouped[$fingerprint]['search_sources'] = array_merge($grouped[$fingerprint]['search_sources'], $parsed['search_sources']);
                } else {
                    $grouped[$fingerprint] = $parsed;
                }
            }

            foreach ($grouped as &$candidate) {
                $candidate['telegram_sources'] = collect($candidate['telegram_sources'] ?? [])
                    ->unique(fn (array $source): string => ($source['chat_id'] ?? 'unknown').':'.($source['message_id'] ?? 'unknown'))
                    ->values()
                    ->all();
                $candidate['search_sources'] = collect($candidate['search_sources'] ?? [])
                    ->unique(fn (array $source): string => ($source['type'] ?? 'unknown').':'.($source['url'] ?? '').':'.($source['message_id'] ?? ''))
                    ->values()
                    ->all();
                $independentSources = count($candidate['search_sources']);
                $candidate['ai_score'] = min(98, $candidate['ai_score'] + max(0, min(12, ($independentSources - 1) * 6)));
            }
            unset($candidate);

            uasort($grouped, fn (array $a, array $b): int => $b['ai_score'] <=> $a['ai_score']);
            $accepted = array_slice(array_filter($grouped, fn (array $bet): bool => $bet['ai_score'] >= $settings->minimum_ai_score), 0, $settings->maximum_results, true);

            $prepared = [];
            $run->update(['progress_percent' => 82, 'status_message' => 'Сверка структурированных коэффициентов']);
            foreach ($accepted as $data) {
                $prepared[] = array_merge($data, $this->odds->lookup($data, $settings));
            }

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
            $run->update([
                'status' => 'completed', 'messages_found' => count($response['messages'] ?? []),
                'bets_found' => count($accepted),
                'last_error' => $errors->isEmpty() ? null : $errors->join(' '),
                'progress_percent' => 100,
                'status_message' => 'Поиск завершён',
                'finished_at' => now(),
            ]);
            $this->cleanup($settings);

            return $run->fresh();
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
                'status_message' => 'Поиск завершился с ошибкой',
                'finished_at' => now(),
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function cleanup(BettingSetting $settings): void
    {
        Bet::query()
            ->where('status', Bet::STATUS_PUBLISHING)
            ->where('updated_at', '<', now()->subMinutes(15))
            ->get()
            ->each->update([
                'status' => Bet::STATUS_PUBLICATION_UNCERTAIN,
                'publication_error' => 'Процесс завершился без подтверждения Telegram. Проверьте канал перед повтором.',
            ]);
        Bet::query()->where('status', Bet::STATUS_FOUND)->where('updated_at', '<', now()->subDays($settings->found_retention_days))->delete();
        Bet::query()->where('status', Bet::STATUS_REJECTED)->where('updated_at', '<', now()->subDays($settings->rejected_retention_days))->delete();
        if ($settings->completed_retention_days) {
            Bet::query()
                ->where('status', Bet::STATUS_PUBLISHED)
                ->whereIn('result', ['win', 'loss', 'refund'])
                ->where('result_checked_at', '<', now()->subDays($settings->completed_retention_days))
                ->delete();
        }
        BetSearchRun::query()->where('created_at', '<', now()->subDays(30))->delete();
    }
}

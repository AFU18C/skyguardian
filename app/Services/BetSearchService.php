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
    ) {}

    public function run(BettingSetting $settings): BetSearchRun
    {
        $run = BetSearchRun::query()->create(['status' => 'running']);
        $account = $settings->technicalAccount;
        if (! $account || ! $account->is_active || $account->status !== 'connected') {
            $run->update(['status' => 'error', 'last_error' => 'Выберите подключённый технический аккаунт.', 'finished_at' => now()]);
            throw new RuntimeException('Выберите подключённый технический аккаунт для поиска ставок.');
        }

        $lock = Cache::lock('betting:manual-search', 600);
        if (! $lock->get()) {
            $run->update(['status' => 'error', 'last_error' => 'Поиск уже выполняется.', 'finished_at' => now()]);
            throw new RuntimeException('Поиск ставок уже выполняется. Дождитесь его завершения.');
        }

        try {
            $response = $this->telethon->call('search_bets', $account, [
                'keywords' => $settings->keywords,
                'freshness_hours' => $settings->freshness_hours,
                'limit' => min(500, max(50, $settings->maximum_results * 20)),
            ]);
            if (! empty($response['session'])) {
                $account->update(['session' => $response['session'], 'last_success_at' => now()]);
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
                    $grouped[$fingerprint]['ai_score'] = min(97, $grouped[$fingerprint]['ai_score'] + 6);
                } else {
                    $grouped[$fingerprint] = $parsed;
                }
            }

            uasort($grouped, fn (array $a, array $b): int => $b['ai_score'] <=> $a['ai_score']);
            $accepted = array_slice(array_filter($grouped, fn (array $bet): bool => $bet['ai_score'] >= $settings->minimum_ai_score), 0, $settings->maximum_results, true);

            $prepared = [];
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
                    $existing->update($data);
                }
            });

            $run->update([
                'status' => 'completed', 'messages_found' => count($response['messages'] ?? []),
                'bets_found' => count($accepted), 'finished_at' => now(),
            ]);
            $this->cleanup($settings);

            return $run->fresh();
        } catch (\Throwable $e) {
            $run->update(['status' => 'error', 'last_error' => $e->getMessage(), 'finished_at' => now()]);
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
        BetSearchRun::query()->where('created_at', '<', now()->subDays(30))->delete();
    }
}

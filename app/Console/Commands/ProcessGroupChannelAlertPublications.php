<?php

namespace App\Console\Commands;

use App\Models\GroupChannelBot;
use App\Services\AlertsInUaClient;
use App\Services\GroupChannelAlertPublicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessGroupChannelAlertPublications extends Command
{
    protected $signature = 'skyguardian:group-channel-alerts:process {--limit=50}';

    protected $description = 'Получает тревоги alerts.in.ua и публикует изменения в подключённые каналы';

    public function handle(
        AlertsInUaClient $client,
        GroupChannelAlertPublicationService $publications,
    ): int {
        $bots = GroupChannelBot::query()
            ->where('is_active', true)
            ->whereNotNull('alerts_api_token')
            ->whereNotNull('chat_id')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->filter(fn (GroupChannelBot $bot): bool => $bot->moduleEnabled(
                GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            ));

        if ($bots->isEmpty()) {
            return self::SUCCESS;
        }

        $groups = $bots->groupBy(fn (GroupChannelBot $bot): string => (string) (
            $bot->alerts_api_token_fingerprint ?: hash('sha256', (string) $bot->alerts_api_token)
        ));
        $group = $this->nextTokenGroup($groups);

        if (! $group) {
            return self::SUCCESS;
        }

        /** @var GroupChannelBot $tokenOwner */
        $tokenOwner = $group->first();

        try {
            $alerts = $client->activeAlerts((string) $tokenOwner->alerts_api_token);
        } catch (Throwable $e) {
            report($e);

            foreach ($group as $bot) {
                $publications->markFailure($bot, $e);
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $failed = false;

        foreach ($group as $bot) {
            try {
                $result = $publications->processSnapshot($bot, $alerts);
                $this->line(sprintf(
                    '#%d %s: активных %d, очередь %d, отправлено %d%s',
                    $bot->id,
                    $bot->group_name,
                    $result['active'],
                    $result['queued'],
                    $result['sent'],
                    $result['baseline'] ? ' (первичная синхронизация)' : '',
                ));
            } catch (Throwable $e) {
                report($e);
                $publications->markFailure($bot, $e);
                $this->error('#'.$bot->id.' '.$bot->group_name.': '.$e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * API ограничивает суммарное количество запросов с одного IP. Поэтому за один
     * десятисекундный запуск обрабатывается только один уникальный API-токен.
     * Если токенов несколько, группы обслуживаются по кругу.
     *
     * @param  Collection<string, Collection<int, GroupChannelBot>>  $groups
     * @return Collection<int, GroupChannelBot>|null
     */
    private function nextTokenGroup(Collection $groups): ?Collection
    {
        $keys = $groups->keys()->values();

        if ($keys->isEmpty()) {
            return null;
        }

        $cursorKey = 'skyguardian:group-channel-alerts:token-cursor';
        $cursor = (int) Cache::get($cursorKey, 0);
        Cache::put($cursorKey, $cursor + 1, now()->addDay());
        $key = (string) $keys[$cursor % $keys->count()];

        return $groups->get($key);
    }
}

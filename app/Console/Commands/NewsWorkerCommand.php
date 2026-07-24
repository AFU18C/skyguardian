<?php

namespace App\Console\Commands;

use App\Jobs\PublishNewsMessage;
use App\Models\NewsPublication;
use App\Models\Source;
use App\Models\TelegramAccount;
use App\Services\NewsPollingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class NewsWorkerCommand extends Command
{
    protected $signature = 'news:work {--once : Выполнить один проход и завершить} {--sleep=1 : Пауза между проходами, секунд}';

    protected $description = 'Poll News Telegram sources using one logical worker per technical account';

    private bool $running = true;

    public function handle(NewsPollingService $polling): int
    {
        $this->registerSignalHandlers();
        $sleep = max(1, (int) $this->option('sleep'));

        do {
            $this->recoverPendingPublications();
            $this->pollDueAccounts($polling);

            if ($this->option('once')) {
                break;
            }

            for ($second = 0; $second < $sleep && $this->running; $second++) {
                sleep(1);
            }
        } while ($this->running);

        return self::SUCCESS;
    }

    private function pollDueAccounts(NewsPollingService $polling): void
    {
        $accounts = TelegramAccount::query()
            ->with('telegramApp')
            ->whereNotNull('telegram_app_id')
            ->where('is_active', true)
            ->whereIn('status', ['connected', 'rate_limited', 'reconnecting'])
            ->whereHas('telegramApp', fn ($query) => $query
                ->where('purpose', 'news')
                ->where('is_active', true))
            ->whereHas('sources', fn ($query) => $query
                ->where('purpose', 'news')
                ->where('is_active', true)
                ->where(fn ($due) => $due
                    ->whereNull('next_check_at')
                    ->orWhere('next_check_at', '<=', now())))
            ->orderBy('id')
            ->get();

        foreach ($accounts as $account) {
            if ($account->flood_wait_until?->isFuture()) {
                continue;
            }

            if (in_array($account->status, ['rate_limited', 'reconnecting'], true)) {
                $account->update([
                    'status' => 'connected',
                    'flood_wait_until' => null,
                ]);
            }

            $lock = Cache::lock('news:telegram-account:'.$account->id, 600);

            if (! $lock->get()) {
                continue;
            }

            try {
                $sources = Source::query()
                    ->where('purpose', 'news')
                    ->where('telegram_account_id', $account->id)
                    ->where('is_active', true)
                    ->where(fn ($due) => $due
                        ->whereNull('next_check_at')
                        ->orWhere('next_check_at', '<=', now()))
                    ->orderBy('next_check_at')
                    ->orderBy('id')
                    ->get();

                foreach ($sources as $source) {
                    $result = $polling->poll($source);

                    if ($result['flood_wait'] !== null) {
                        break;
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
                $account->update([
                    'status' => 'reconnecting',
                    'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                ]);
            } finally {
                $lock->release();
            }
        }
    }

    private function recoverPendingPublications(): void
    {
        NewsPublication::query()
            ->where('status', NewsPublication::STATUS_PROCESSING)
            ->where('last_attempt_at', '<=', now()->subMinutes(10))
            ->update([
                'status' => NewsPublication::STATUS_PENDING,
                'available_at' => now(),
                'last_error' => 'Публикация восстановлена после прерванного worker.',
            ]);

        NewsPublication::query()
            ->where('status', NewsPublication::STATUS_PENDING)
            ->where(fn ($query) => $query
                ->whereNull('available_at')
                ->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->each(fn (int $id) => PublishNewsMessage::dispatch($id)->onQueue('news'));
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->running = false);
        pcntl_signal(SIGINT, fn () => $this->running = false);
    }
}

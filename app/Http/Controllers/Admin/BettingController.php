<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\BetSearchRun;
use App\Models\BettingSetting;
use App\Models\GroupChannelBot;
use App\Models\TechnicalAccount;
use App\Services\BetPublicationService;
use App\Services\BetResultService;
use App\Services\BetSearchService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class BettingController extends Controller
{
    public function index(Request $request): View
    {
        $settings = BettingSetting::current();
        $tab = in_array($request->string('tab')->value(), ['overview', 'search', 'telegram_sources', 'website_sources', 'published', 'statistics', 'sources', 'channels', 'settings'], true)
            ? $request->string('tab')->value() : 'overview';
        $statistics = [
            'total' => Bet::query()->where('status', Bet::STATUS_PUBLISHED)->count(),
            'pending' => Bet::query()->where('status', Bet::STATUS_PUBLISHED)->where(fn ($q) => $q->whereNull('result')->orWhere('result', 'pending'))->count(),
            'wins' => Bet::query()->where('status', Bet::STATUS_PUBLISHED)->where('result', 'win')->count(),
            'losses' => Bet::query()->where('status', Bet::STATUS_PUBLISHED)->where('result', 'loss')->count(),
            'refunds' => Bet::query()->where('status', Bet::STATUS_PUBLISHED)->where('result', 'refund')->count(),
        ];
        $decided = $statistics['wins'] + $statistics['losses'];
        $statistics['success_rate'] = $decided ? round($statistics['wins'] / $decided * 100, 1) : 0;

        return view('admin.betting.index', [
            'tab' => $tab,
            'settings' => $settings,
            'statistics' => $statistics,
            'foundBets' => Bet::query()->where('status', Bet::STATUS_FOUND)->latest()->paginate(10, ['*'], 'found_page'),
            'publishedBets' => Bet::query()->where('status', Bet::STATUS_PUBLISHED)->latest('published_at')->paginate(10, ['*'], 'published_page'),
            'latestRun' => BetSearchRun::query()->latest()->first(),
            'technicalAccounts' => TechnicalAccount::query()->where('is_active', true)->orderBy('name')->get(),
            'bots' => GroupChannelBot::query()->where('is_active', true)->orderBy('group_name')->get(),
        ]);
    }

    public function search(Request $request, BetSearchService $service): RedirectResponse
    {
        $mode = $request->validate([
            'search_mode' => ['required', Rule::in(['telegram', 'websites', 'all'])],
        ])['search_mode'];

        try {
            $run = $service->run(BettingSetting::current(), $mode);

            return redirect()->route('admin.betting.index', ['tab' => 'search'])->with('toast', [
                'type' => 'success', 'title' => 'Проверка завершена',
                'message' => "Найдено сообщений: {$run->messages_found}; подходящих ставок: {$run->bets_found}.",
            ]);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('admin.betting.index', ['tab' => 'search'])->with('toast', [
                'type' => 'error', 'title' => 'Поиск не выполнен', 'message' => $e->getMessage(),
            ]);
        }
    }

    public function updateSettings(Request $request, BetSearchService $service): RedirectResponse
    {
        $data = $request->validate([
            'technical_account_id' => ['nullable', 'exists:technical_accounts,id'],
            'publication_bot_id' => ['nullable', 'exists:group_channel_bots,id'],
            'keywords_text' => ['required', 'string', 'max:10000'],
            'telegram_channels_text' => ['nullable', 'string', 'max:20000'],
            'website_sources_text' => ['nullable', 'string', 'max:30000'],
            'freshness_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'minimum_ai_score' => ['required', 'integer', 'min:1', 'max:100'],
            'maximum_results' => ['required', 'integer', 'min:1', 'max:100'],
            'primary_source_name' => ['required', 'string', 'max:100'],
            'primary_source_url' => ['required', 'url:http,https', 'max:2048'],
            'reserve_source_name' => ['nullable', 'string', 'max:100'],
            'reserve_source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'found_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'rejected_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'completed_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        $data['keywords'] = collect(preg_split('/\R/u', $data['keywords_text']))
            ->map(fn (string $keyword): string => trim($keyword))
            ->filter()
            ->unique()
            ->values()
            ->all();
        unset($data['keywords_text']);
        if (empty($data['keywords'])) {
            return back()->withErrors(['keywords_text' => 'Добавьте хотя бы одну поисковую фразу.'])->withInput();
        }
        $channelLines = collect(preg_split('/\R/u', (string) ($data['telegram_channels_text'] ?? '')))
            ->map(fn (string $channel): string => trim($channel))
            ->filter()
            ->values();
        $invalidChannels = $channelLines
            ->filter(fn (string $channel): bool => $this->normalizeTelegramChannel($channel) === null)
            ->values();
        if ($invalidChannels->isNotEmpty()) {
            return back()->withErrors([
                'telegram_channels_text' => 'Неверный Telegram-канал: '.$invalidChannels->first().'. Укажите @username, ссылку t.me/username, приватную ссылку t.me/+… или ID -100…',
            ])->withInput();
        }
        $data['telegram_channels'] = $channelLines
            ->map(fn (string $channel): string => $this->normalizeTelegramChannel($channel))
            ->unique(fn (string $channel): string => mb_strtolower($channel))
            ->values()
            ->all();
        if (count($data['telegram_channels']) > 100) {
            return back()->withErrors(['telegram_channels_text' => 'Можно добавить не более 100 Telegram-каналов.'])->withInput();
        }
        unset($data['telegram_channels_text']);
        $websiteLines = collect(preg_split('/\R/u', (string) ($data['website_sources_text'] ?? '')))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values();
        $websiteSources = $websiteLines->map(function (string $line): array {
            $enabled = ! str_starts_with($line, '#');
            $line = trim(ltrim($line, '#'));
            $parts = preg_split('/\s*\|\s*/u', $line, 2);
            $url = count($parts) === 2 ? trim($parts[1]) : trim($parts[0]);
            $name = count($parts) === 2 ? trim($parts[0]) : (parse_url($url, PHP_URL_HOST) ?: 'Сайт');

            return ['name' => $name, 'url' => $url, 'enabled' => $enabled];
        });
        $invalidWebsite = $websiteSources->first(fn (array $source): bool => $source['name'] === ''
            || mb_strlen($source['name']) > 100
            || filter_var($source['url'], FILTER_VALIDATE_URL) === false
            || ! in_array(parse_url($source['url'], PHP_URL_SCHEME), ['http', 'https'], true));
        if ($invalidWebsite) {
            return back()->withErrors([
                'website_sources_text' => 'Неверный сайт: '.($invalidWebsite['url'] ?: 'пустой адрес').'. Используйте формат «Название | https://адрес».',
            ])->withInput();
        }
        if ($websiteSources->count() > 50) {
            return back()->withErrors(['website_sources_text' => 'Можно добавить не более 50 сайтов.'])->withInput();
        }
        $data['website_sources'] = $websiteSources
            ->unique(fn (array $source): string => mb_strtolower($source['url']))
            ->values()
            ->all();
        unset($data['website_sources_text']);
        $settings = BettingSetting::current();
        $settings->update($data);
        $service->cleanup($settings->fresh());

        return back()->with('toast', ['type' => 'success', 'title' => 'Настройки сохранены', 'message' => 'Параметры модуля «Ставки» обновлены.']);
    }

    public function approve(Request $request, Bet $bet, BetPublicationService $publisher): RedirectResponse
    {
        $settings = BettingSetting::current();
        $data = $request->validate([
            'selected_odds' => ['nullable', 'numeric', 'min:1.001', 'max:9999'],
            'publication_bot_id' => ['nullable', 'exists:group_channel_bots,id'],
        ]);
        $odds = isset($data['selected_odds']) ? (float) $data['selected_odds'] : (float) ($bet->primary_odds ?: $bet->reserve_odds);
        if ($odds <= 1) {
            return back()->with('toast', ['type' => 'error', 'title' => 'Нет коэффициента', 'message' => 'Укажите свой коэффициент перед публикацией.']);
        }
        $bot = GroupChannelBot::query()->where('is_active', true)->find($data['publication_bot_id'] ?? $settings->publication_bot_id);
        if (! $bot) {
            return back()->with('toast', ['type' => 'error', 'title' => 'Канал не выбран', 'message' => 'Выберите канал публикации в настройках.']);
        }
        $source = match (true) {
            $bet->primary_odds !== null && abs($odds - (float) $bet->primary_odds) < 0.0005 => data_get($bet->odds_snapshot, 'primary.name', $settings->primary_source_name),
            $bet->reserve_odds !== null && abs($odds - (float) $bet->reserve_odds) < 0.0005 => data_get($bet->odds_snapshot, 'reserve.name', $settings->reserve_source_name),
            default => 'Администратор',
        };

        try {
            $claimed = Bet::query()
                ->whereKey($bet->getKey())
                ->where('status', Bet::STATUS_FOUND)
                ->update([
                    'status' => Bet::STATUS_PUBLISHING,
                    'publication_guard' => $bet->fingerprint,
                    'selected_odds' => $odds,
                    'selected_odds_source' => $source,
                    'publication_bot_id' => $bot->id,
                    'updated_at' => now(),
                ]);
        } catch (QueryException $e) {
            if (Bet::query()->where('publication_guard', $bet->fingerprint)->exists()) {
                return back()->with('toast', ['type' => 'warning', 'title' => 'Ставка уже опубликована', 'message' => 'Повторная публикация одинаковой ставки заблокирована.']);
            }

            throw $e;
        }

        if ($claimed !== 1) {
            return back()->with('toast', ['type' => 'warning', 'title' => 'Ставка уже обработана', 'message' => 'Повторная публикация заблокирована.']);
        }

        $bet->refresh();
        try {
            $messageId = $publisher->publish($bet, $bot);
            $bet->update(['status' => Bet::STATUS_PUBLISHED, 'telegram_message_id' => $messageId, 'published_at' => now(), 'result' => 'pending']);

            return back()->with('toast', ['type' => 'success', 'title' => 'Ставка опубликована', 'message' => 'Бот отправил одобренную ставку в выбранный канал.']);
        } catch (Throwable $e) {
            $bet->update(['status' => Bet::STATUS_FOUND]);
            report($e);

            return back()->with('toast', ['type' => 'error', 'title' => 'Ошибка публикации', 'message' => $e->getMessage()]);
        }
    }

    public function reject(Bet $bet): RedirectResponse
    {
        abort_unless($bet->status === Bet::STATUS_FOUND, 404);
        $bet->update(['status' => Bet::STATUS_REJECTED]);

        return back()->with('toast', ['type' => 'success', 'title' => 'Отклонено', 'message' => 'Ставка перемещена в архив.']);
    }

    public function update(Request $request, Bet $bet): RedirectResponse
    {
        $data = $request->validate([
            'event_name' => ['required', 'string', 'max:255'], 'tournament' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'], 'market' => ['required', 'string', 'max:255'],
            'selected_odds' => ['nullable', 'numeric', 'min:1.001', 'max:9999'],
            'ai_score' => ['required', 'integer', 'min:1', 'max:100'],
            'publication_text' => ['nullable', 'string', 'max:4096'],
            'result' => ['nullable', Rule::in(Bet::RESULTS)], 'result_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $history = $bet->edit_history ?? [];
        $history[] = ['at' => now()->toIso8601String(), 'user_id' => auth()->id(), 'before' => $bet->only(array_keys($data))];
        $data['edit_history'] = array_slice($history, -50);
        if (array_key_exists('selected_odds', $data)
            && (float) $data['selected_odds'] !== (float) $bet->selected_odds) {
            $data['selected_odds_source'] = 'Администратор';
        }
        if (! empty($data['result']) && $data['result'] !== 'pending') {
            $data['result_checked_at'] = now();
        }
        $bet->update($data);

        return back()->with('toast', ['type' => 'success', 'title' => 'Ставка обновлена', 'message' => 'Изменения сохранены в истории.']);
    }

    public function sendResult(Request $request, Bet $bet, BetPublicationService $publisher): RedirectResponse
    {
        abort_unless($bet->status === Bet::STATUS_PUBLISHED, 404);
        if (! in_array($bet->result, ['win', 'loss', 'refund'], true)) {
            return back()->with('toast', ['type' => 'error', 'title' => 'Результат не определён', 'message' => 'Сначала установите итог ставки.']);
        }
        $data = $request->validate(['publication_bot_id' => ['nullable', 'exists:group_channel_bots,id'], 'text' => ['nullable', 'string', 'max:4096']]);
        $bot = GroupChannelBot::query()->where('is_active', true)->find($data['publication_bot_id'] ?? $bet->publication_bot_id);
        abort_unless($bot, 422, 'Канал публикации не найден.');
        try {
            $messageId = $publisher->sendResult($bet, $bot, $data['text'] ?? null);
            $bet->update(['result_message_id' => $messageId, 'result_sent_at' => now()]);

            return back()->with('toast', ['type' => 'success', 'title' => 'Результат отправлен', 'message' => 'Бот опубликовал результат ставки.']);
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'title' => 'Ошибка отправки', 'message' => $e->getMessage()]);
        }
    }

    public function checkResult(Bet $bet, BetResultService $service): RedirectResponse
    {
        abort_unless($bet->status === Bet::STATUS_PUBLISHED, 404);
        $bet->update($service->check($bet, BettingSetting::current()));
        $message = $bet->fresh()->result === 'pending'
            ? 'Событие не завершено или официальный результат ещё не опубликован.'
            : 'Результат ставки определён автоматически.';

        return back()->with('toast', [
            'type' => $bet->fresh()->result === 'pending' ? 'warning' : 'success',
            'title' => $bet->fresh()->result === 'pending' ? 'Результат ещё недоступен' : 'Результат проверен',
            'message' => $message,
        ]);
    }

    public function clearArchive(Request $request): RedirectResponse
    {
        $scope = $request->validate([
            'scope' => ['required', Rule::in(['found', 'rejected', 'completed', 'search_runs'])],
        ])['scope'];

        $deleted = match ($scope) {
            'found' => Bet::query()->where('status', Bet::STATUS_FOUND)->delete(),
            'rejected' => Bet::query()->where('status', Bet::STATUS_REJECTED)->delete(),
            'completed' => Bet::query()->where('status', Bet::STATUS_PUBLISHED)->whereIn('result', ['win', 'loss', 'refund'])->delete(),
            'search_runs' => BetSearchRun::query()->delete(),
        };

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Архив очищен',
            'message' => "Удалено записей: {$deleted}.",
        ]);
    }

    private function normalizeTelegramChannel(string $channel): ?string
    {
        if (preg_match('/^-100\d{5,20}$/', $channel) === 1) {
            return $channel;
        }

        if (preg_match('~^(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me)/(?:\+|joinchat/)([A-Za-z0-9_-]{10,128})/?(?:\?.*)?$~i', $channel, $matches) === 1) {
            return 'https://t.me/+'.$matches[1];
        }

        if (preg_match('~^(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me)/(?:s/)?([A-Za-z][A-Za-z0-9_]{4,31})(?:/\d+)?/?(?:\?.*)?$~i', $channel, $matches) === 1) {
            return '@'.mb_strtolower($matches[1]);
        }

        if (preg_match('/^@?([A-Za-z][A-Za-z0-9_]{4,31})$/', $channel, $matches) === 1) {
            return '@'.mb_strtolower($matches[1]);
        }

        return null;
    }
}

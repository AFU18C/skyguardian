<?php

namespace App\Http\Controllers;

use App\Contracts\TelegramGateway;
use App\Models\Source;
use App\Models\TelegramAccount;
use App\Services\NewsPollingService;
use App\Services\TelegramFloodWait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class NewsChannelsController extends Controller
{
    public function __construct(
        private readonly TelegramGateway $telegram,
        private readonly NewsPollingService $polling,
    ) {
    }

    public function index(): View
    {
        return view('admin.news-channels', [
            'channels' => Source::query()
                ->with('telegramAccount.telegramApp')
                ->where('purpose', 'news')
                ->latest()
                ->get(),
            'hasConnectedAccounts' => $this->accounts()->exists(),
        ]);
    }

    public function create(): View|RedirectResponse
    {
        if (! $this->accounts()->exists()) {
            return redirect()
                ->route('news.settings')
                ->withErrors(['telegram' => 'Сначала добавьте и подключите технический аккаунт Telegram.']);
        }

        return view('admin.news-channel-form', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['purpose'] = 'news';
        $data['type'] = 'telegram';
        $data['is_active'] = true;
        $data['remove_links'] = true;
        $data['remove_hashtags'] = true;
        $inspection = $this->inspect($data);
        $this->ensureUniquePeers($data, $inspection);
        $data['peer_id'] = $inspection['source_peer_id'];
        $data['publication_peer_id'] = $inspection['destination_peer_id'];
        $data['last_message_id'] = $inspection['latest_message_id'];
        $data['next_check_at'] = now()->addSeconds($data['check_interval_seconds']);
        $data['is_available'] = true;
        $data['resume_from_latest'] = false;
        $data['last_error'] = null;

        Source::query()->create($data);

        return redirect()->route('news.channels')->with('status', 'Канал данных добавлен.');
    }

    public function edit(Source $channel): View
    {
        $this->ensureNewsChannel($channel);

        return view('admin.news-channel-form', $this->formData($channel));
    }

    public function update(Request $request, Source $channel): RedirectResponse
    {
        $this->ensureNewsChannel($channel);

        $data = $this->validated($request, $channel);
        $data['remove_links'] = true;
        $data['remove_hashtags'] = true;
        $inspection = $this->inspect($data);
        $this->ensureUniquePeers($data, $inspection, $channel);
        $sourceChanged = $channel->telegram_account_id !== (int) $data['telegram_account_id']
            || $channel->identifier !== $data['identifier'];

        $data['peer_id'] = $inspection['source_peer_id'];
        $data['publication_peer_id'] = $inspection['destination_peer_id'];
        $data['is_available'] = true;
        $data['last_error'] = null;

        if ($sourceChanged) {
            $data['last_message_id'] = $inspection['latest_message_id'];
            $data['resume_from_latest'] = false;
        }

        if ($channel->is_active) {
            $data['next_check_at'] = now()->addSeconds($data['check_interval_seconds']);
        }

        $channel->update($data);

        return redirect()->route('news.channels')->with('status', 'Канал данных сохранён.');
    }

    public function toggle(Source $channel): RedirectResponse
    {
        $this->ensureNewsChannel($channel);

        $enabling = ! $channel->is_active;

        if ($enabling && ! $this->accounts()->whereKey($channel->telegram_account_id)->exists()) {
            return back()->withErrors([
                'telegram' => 'Чтобы включить канал, сначала выберите подключённый технический аккаунт.',
            ]);
        }

        $channel->update([
            'is_active' => $enabling,
            'resume_from_latest' => $enabling,
            'next_check_at' => $enabling ? now() : null,
            'last_error' => null,
        ]);

        return redirect()->route('news.channels')->with('status', 'Статус канала изменён.');
    }

    public function checkAccess(Source $channel): RedirectResponse
    {
        $this->ensureNewsChannel($channel);

        if (! $channel->telegramAccount) {
            return back()->withErrors([
                'telegram' => 'Технический аккаунт удалён. Выберите другой аккаунт в редактировании канала.',
            ]);
        }

        if (! $this->accounts()->whereKey($channel->telegram_account_id)->exists()) {
            return back()->withErrors(['telegram' => 'Подключённый технический аккаунт недоступен.']);
        }

        $lock = Cache::lock('news:telegram-account:'.$channel->telegram_account_id, 120);

        if (! $lock->get()) {
            return back()->withErrors(['telegram' => 'Техаккаунт уже используется другой проверкой.']);
        }

        try {
            $inspection = $this->telegram->inspect(
                $channel->telegramAccount,
                $channel->identifier,
                (string) $channel->publication_identifier,
            );
            $channel->update([
                'peer_id' => $inspection['source_peer_id'],
                'publication_peer_id' => $inspection['destination_peer_id'],
                'is_available' => true,
                'last_manual_checked_at' => now(),
                'last_error' => null,
            ]);

            return back()->with('status', 'Доступ к источнику и каналу публикации подтверждён.');
        } catch (Throwable $exception) {
            $wait = TelegramFloodWait::seconds($exception);

            if ($wait !== null) {
                $until = now()->addSeconds($wait);
                $channel->telegramAccount->update([
                    'status' => 'rate_limited',
                    'flood_wait_until' => $until,
                    'last_error' => null,
                ]);
                $channel->update([
                    'flood_wait_until' => $until,
                    'last_manual_checked_at' => now(),
                    'last_error' => null,
                ]);
            } else {
                $channel->update([
                    'is_available' => false,
                    'last_manual_checked_at' => now(),
                    'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                ]);
            }

            return back()->withErrors(['telegram' => $exception->getMessage()]);
        } finally {
            $lock->release();
        }
    }

    public function checkNow(Source $channel): RedirectResponse
    {
        $this->ensureNewsChannel($channel);

        if (! $channel->is_active) {
            return back()->withErrors(['telegram' => 'Сначала включите канал данных.']);
        }

        if (! $this->accounts()->whereKey($channel->telegram_account_id)->exists()) {
            return back()->withErrors(['telegram' => 'Подключённый технический аккаунт недоступен.']);
        }

        $lock = Cache::lock('news:telegram-account:'.$channel->telegram_account_id, 600);

        if (! $lock->get()) {
            return back()->withErrors(['telegram' => 'Этот техаккаунт уже выполняет проверку. Повторите через несколько секунд.']);
        }

        try {
            $result = $this->polling->poll($channel, true);
        } finally {
            $lock->release();
        }

        if ($result['flood_wait'] !== null) {
            return back()->with('status', 'Telegram временно ограничил запросы. Повтор через '.$result['flood_wait'].' сек.');
        }

        return back()->with(
            'status',
            'Проверка завершена. Новых сообщений: '.$result['received'].', поставлено в публикацию: '.$result['queued'].'.',
        );
    }

    public function destroy(Source $channel): RedirectResponse
    {
        $this->ensureNewsChannel($channel);

        $channel->delete();

        return redirect()->route('news.channels')->with('status', 'Канал данных удалён.');
    }

    private function formData(?Source $channel = null): array
    {
        $interval = $channel?->check_interval_seconds ?? 3;
        $unit = 'seconds';
        $value = $interval;

        if ($interval >= 3600 && $interval % 3600 === 0) {
            $unit = 'hours';
            $value = intdiv($interval, 3600);
        } elseif ($interval >= 60 && $interval % 60 === 0) {
            $unit = 'minutes';
            $value = intdiv($interval, 60);
        }

        return [
            'editing' => $channel !== null,
            'channel' => $channel,
            'accounts' => $this->accounts()->get(),
            'frequencyUnit' => $unit,
            'frequencyValue' => $value,
        ];
    }

    private function validated(Request $request, ?Source $channel = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'identifier' => [
                'required',
                'string',
                'max:255',
            ],
            'telegram_account_id' => [
                'required',
                Rule::exists('telegram_accounts', 'id')->where('purpose', 'news'),
            ],
            'publication_identifier' => ['required', 'string', 'max:255'],
            'publication_format' => ['required', Rule::in(['original', 'text'])],
            'keywords' => ['nullable', 'string', 'max:2000'],
            'stop_words' => ['nullable', 'string', 'max:2000'],
            'append_custom_text' => ['nullable', 'boolean'],
            'custom_text' => ['nullable', 'string', 'max:4000'],
            'frequency_value' => ['required', 'integer'],
            'frequency_unit' => ['required', Rule::in(['seconds', 'minutes', 'hours'])],
        ]);

        $multiplier = match ($data['frequency_unit']) {
            'minutes' => 60,
            'hours' => 3600,
            default => 1,
        };
        $seconds = $data['frequency_value'] * $multiplier;

        if ($seconds < 3 || $seconds > 43200) {
            throw ValidationException::withMessages([
                'frequency_value' => 'Частота проверки должна быть от 3 секунд до 12 часов.',
            ]);
        }

        unset($data['frequency_value'], $data['frequency_unit']);
        $data['identifier'] = trim($data['identifier']);
        $data['publication_identifier'] = trim($data['publication_identifier']);
        $data['append_custom_text'] = (bool) ($data['append_custom_text'] ?? false);
        $data['custom_text'] = $data['append_custom_text'] ? ($data['custom_text'] ?? null) : null;
        $data['check_interval_seconds'] = $seconds;

        $duplicate = Source::query()
            ->where('telegram_account_id', $data['telegram_account_id'])
            ->where('identifier', $data['identifier'])
            ->where('publication_identifier', $data['publication_identifier'])
            ->when($channel, fn ($query) => $query->whereKeyNot($channel->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'identifier' => 'Такая связка источника, техаккаунта и канала публикации уже добавлена.',
            ]);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{source_peer_id: string, destination_peer_id: string, latest_message_id: int|null}
     */
    private function inspect(array $data): array
    {
        $account = $this->accounts()->find($data['telegram_account_id']);

        if (! $account) {
            throw ValidationException::withMessages([
                'telegram_account_id' => 'Выберите подключённый активный технический аккаунт.',
            ]);
        }

        try {
            $lock = Cache::lock('news:telegram-account:'.$account->id, 120);

            if (! $lock->get()) {
                throw new \RuntimeException('Техаккаунт уже используется другой проверкой.');
            }

            try {
                return $this->telegram->inspect(
                    $account,
                    $data['identifier'],
                    $data['publication_identifier'],
                );
            } finally {
                $lock->release();
            }
        } catch (Throwable $exception) {
            $wait = TelegramFloodWait::seconds($exception);

            if ($wait !== null) {
                $account->update([
                    'status' => 'rate_limited',
                    'flood_wait_until' => now()->addSeconds($wait),
                    'last_error' => null,
                ]);
            }

            throw ValidationException::withMessages([
                'identifier' => 'Проверка Telegram не пройдена: '.$exception->getMessage(),
            ]);
        }
    }

    private function accounts(): Builder
    {
        return TelegramAccount::query()
            ->forPurpose('news')
            ->whereNotNull('telegram_app_id')
            ->where('status', 'connected')
            ->where('is_active', true)
            ->whereHas('telegramApp', fn ($query) => $query
                ->where('purpose', 'news')
                ->where('is_active', true))
            ->orderBy('name');
    }

    /**
     * @param array<string, mixed> $data
     * @param array{source_peer_id: string, destination_peer_id: string, latest_message_id: int|null} $inspection
     */
    private function ensureUniquePeers(
        array $data,
        array $inspection,
        ?Source $channel = null,
    ): void {
        $duplicate = Source::query()
            ->where('telegram_account_id', $data['telegram_account_id'])
            ->where('peer_id', $inspection['source_peer_id'])
            ->where('publication_peer_id', $inspection['destination_peer_id'])
            ->when($channel, fn ($query) => $query->whereKeyNot($channel->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'identifier' => 'Такая связка источника, техаккаунта и канала публикации уже добавлена.',
            ]);
        }
    }

    private function ensureNewsChannel(Source $channel): void
    {
        abort_unless($channel->purpose === 'news', 404);
    }
}

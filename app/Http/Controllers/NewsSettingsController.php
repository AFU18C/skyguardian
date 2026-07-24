<?php

namespace App\Http\Controllers;

use App\Models\TelegramApp;
use App\Services\TelegramSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewsSettingsController extends Controller
{
    public function __construct(private readonly TelegramSessionService $sessions)
    {
    }

    public function index(): View
    {
        return view('admin.news-settings', [
            'apps' => TelegramApp::query()
                ->forPurpose('news')
                ->with(['accounts' => fn ($query) => $query
                    ->with('telegramApp')
                    ->orderBy('name')])
                ->latest()
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.news-setting-form', [
            'editing' => false,
            'telegramApp' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(TelegramApp::query()->forPurpose('news')->count() >= 10, 422, 'Можно добавить не более 10 Telegram App.');

        $data = $this->validated($request);
        $data['purpose'] = 'news';

        $telegramApp = TelegramApp::query()->create($data);

        return redirect()
            ->route('news.settings.edit', $telegramApp)
            ->with('status', 'Telegram App сохранён. Теперь добавьте технический аккаунт.');
    }

    public function edit(TelegramApp $telegramApp): View
    {
        $this->ensureNewsApp($telegramApp);
        $telegramApp->load(['accounts' => fn ($query) => $query->orderBy('name')]);

        return view('admin.news-setting-form', [
            'editing' => true,
            'telegramApp' => $telegramApp,
        ]);
    }

    public function update(Request $request, TelegramApp $telegramApp): RedirectResponse
    {
        $this->ensureNewsApp($telegramApp);

        $data = $this->validated($request, $telegramApp);
        $credentialsChanged = (string) $data['api_id'] !== $telegramApp->api_id
            || filled($data['api_hash'] ?? null);

        if (blank($data['api_hash'] ?? null)) {
            unset($data['api_hash']);
        }

        if ($credentialsChanged) {
            $accounts = $telegramApp->accounts()->get();
            $locks = [];

            foreach ($accounts as $account) {
                $lock = Cache::lock('news:telegram-account:'.$account->id, 180);

                if (! $lock->get()) {
                    foreach ($locks as $heldLock) {
                        $heldLock->release();
                    }

                    throw ValidationException::withMessages([
                        'telegram_app' => 'Один из техаккаунтов сейчас выполняет проверку. Повторите через несколько секунд.',
                    ]);
                }

                $locks[] = $lock;
            }

            try {
                $telegramApp->update($data);

                foreach ($accounts as $account) {
                    $this->sessions->purge($account);
                    $account->update([
                        'status' => 'not_connected',
                        'connected_at' => null,
                        'last_error' => 'Данные Telegram App изменены. Подключите техаккаунт заново.',
                    ]);
                    $account->sources()
                        ->where('purpose', 'news')
                        ->update([
                            'resume_from_latest' => true,
                            'next_check_at' => null,
                        ]);
                }
            } finally {
                foreach ($locks as $lock) {
                    $lock->release();
                }
            }
        } else {
            $telegramApp->update($data);
        }

        return redirect()
            ->route('news.settings.edit', $telegramApp)
            ->with('status', 'Telegram App сохранён.');
    }

    public function toggle(TelegramApp $telegramApp): RedirectResponse
    {
        $this->ensureNewsApp($telegramApp);
        $enabling = ! $telegramApp->is_active;
        $telegramApp->update(['is_active' => $enabling]);

        $telegramApp->accounts()
            ->with('sources')
            ->get()
            ->each(function ($account) use ($enabling): void {
                $account->sources()
                    ->where('purpose', 'news')
                    ->where('is_active', true)
                    ->update([
                        'resume_from_latest' => $enabling,
                        'next_check_at' => $enabling ? now() : null,
                    ]);
            });

        return redirect()->route('news.settings')->with('status', 'Статус Telegram App изменён.');
    }

    public function destroy(TelegramApp $telegramApp): RedirectResponse
    {
        $this->ensureNewsApp($telegramApp);

        if ($telegramApp->accounts()->exists()) {
            throw ValidationException::withMessages([
                'telegram_app' => 'Сначала удалите все технические аккаунты этого Telegram App.',
            ]);
        }

        $telegramApp->delete();

        return redirect()->route('news.settings')->with('status', 'Telegram App удалён.');
    }

    private function validated(Request $request, ?TelegramApp $telegramApp = null): array
    {
        $editing = $telegramApp !== null;

        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('telegram_apps', 'name')
                    ->where(fn ($query) => $query->where('purpose', 'news'))
                    ->ignore($telegramApp?->id),
            ],
            'api_id' => ['required', 'digits_between:4,12'],
            'api_hash' => [
                $editing ? 'nullable' : 'required',
                'string',
                'size:32',
                'regex:/^[a-f0-9]{32}$/i',
            ],
        ]);
    }

    private function ensureNewsApp(TelegramApp $telegramApp): void
    {
        abort_unless($telegramApp->purpose === 'news', 404);
    }
}

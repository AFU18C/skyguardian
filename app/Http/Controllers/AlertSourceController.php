<?php

namespace App\Http\Controllers;

use App\Models\AlertSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

class AlertSourceController extends Controller
{
    public function index(): View
    {
        $sources = AlertSource::query()->latest()->get();

        $sources->each(function (AlertSource $source): void {
            $source->setAttribute('manual_check_status', Cache::get($this->statusCacheKey($source)));
            $source->setAttribute('manual_checked_at', Cache::get($this->checkedAtCacheKey($source)));
        });

        return view('pages.alerts.sources', compact('sources'));
    }

    public function create(): View
    {
        return view('pages.alerts.source-create');
    }

    public function store(Request $request): RedirectResponse
    {
        AlertSource::query()->create($this->validated($request));

        return redirect()
            ->route('alerts.sources')
            ->with('success', 'Источник сохранён.');
    }

    public function edit(AlertSource $source): View
    {
        return view('pages.alerts.source-edit', compact('source'));
    }

    public function update(Request $request, AlertSource $source): RedirectResponse
    {
        $source->update($this->validated($request));

        return redirect()
            ->route('alerts.sources')
            ->with('success', 'Источник обновлён.');
    }

    public function destroy(AlertSource $source): RedirectResponse
    {
        Cache::forget($this->statusCacheKey($source));
        Cache::forget($this->checkedAtCacheKey($source));
        $source->delete();

        return redirect()
            ->route('alerts.sources')
            ->with('success', 'Источник удалён.');
    }

    public function test(AlertSource $source): JsonResponse
    {
        if ($source->type !== 'telegram') {
            return response()->json([
                'ok' => false,
                'message' => 'Проверка сейчас доступна только для Telegram-источников.',
            ], 422);
        }

        $channel = $this->telegramChannelName($source->address);

        if ($channel === null) {
            return $this->manualCheckResponse(
                $source,
                false,
                'Некорректная ссылка Telegram. Укажите публичный канал вида https://t.me/channel_name.',
                422,
            );
        }

        try {
            $response = Http::accept('text/html,application/xhtml+xml')
                ->withUserAgent('Mozilla/5.0 (compatible; SkyGuardian/0.6; +https://localhost)')
                ->timeout(12)
                ->retry(1, 300, throw: false)
                ->get("https://t.me/s/{$channel}");

            if (! $response->successful()) {
                return $this->manualCheckResponse(
                    $source,
                    false,
                    'Telegram-канал недоступен или не существует.',
                    422,
                );
            }

            if (! str_contains($response->body(), 'tgme_widget_message')) {
                return $this->manualCheckResponse(
                    $source,
                    false,
                    'Публикации не найдены. Возможно, канал закрытый, пустой или ссылка неверна.',
                    422,
                );
            }

            return $this->manualCheckResponse(
                $source,
                true,
                'Источник работает: публикации Telegram доступны.',
            );
        } catch (ConnectionException) {
            return $this->manualCheckResponse(
                $source,
                false,
                'Не удалось подключиться к Telegram. Проверьте интернет-соединение сервера.',
                503,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->manualCheckResponse(
                $source,
                false,
                'Ошибка проверки Telegram-источника. Повторите попытку позже.',
                500,
            );
        }
    }

    private function manualCheckResponse(
        AlertSource $source,
        bool $available,
        string $message,
        int $status = 200,
    ): JsonResponse {
        $checkedAt = now();
        $manualStatus = $available ? 'available' : 'unavailable';

        Cache::forever($this->statusCacheKey($source), $manualStatus);
        Cache::forever($this->checkedAtCacheKey($source), $checkedAt->toIso8601String());

        return response()->json([
            'ok' => $available,
            'message' => $message,
            'manual_status' => $manualStatus,
            'manual_checked_at' => $checkedAt->format('d.m.Y H:i:s'),
        ], $status);
    }

    private function statusCacheKey(AlertSource $source): string
    {
        return "alert-source:{$source->getKey()}:manual-status";
    }

    private function checkedAtCacheKey(AlertSource $source): string
    {
        return "alert-source:{$source->getKey()}:manual-checked-at";
    }

    private function telegramChannelName(string $url): ?string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if (str_starts_with($path, 's/')) {
            $path = substr($path, 2);
        }

        $channel = explode('/', $path)[0] ?? '';

        return preg_match('/^[A-Za-z0-9_]{5,32}$/', $channel) === 1
            ? $channel
            : null;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:api,telegram,website'],
            'address' => [
                'required',
                'url',
                'starts_with:https://',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    if ($request->input('type') !== 'telegram') {
                        return;
                    }

                    if (! preg_match('~^https://t\.me/(?:s/)?[A-Za-z0-9_]{5,32}/?$~i', (string) $value)) {
                        $fail('Для Telegram укажите публичную ссылку вида https://t.me/channel_name.');
                    }
                },
            ],
            'publication_chat' => [
                'required',
                'url',
                'starts_with:https://',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! preg_match('~^https://t\.me/[A-Za-z0-9_+\-/]+$~i', (string) $value)) {
                        $fail('Укажите ссылку вида https://t.me/channel_name.');
                    }
                },
            ],
            'check_interval' => ['required', 'integer', 'min:1', 'max:86400'],
        ], [
            'address.url' => 'Укажите корректную HTTPS-ссылку.',
            'address.starts_with' => 'Адрес источника должен начинаться с https://.',
            'publication_chat.url' => 'Укажите корректную HTTPS-ссылку Telegram.',
            'publication_chat.starts_with' => 'Ссылка для публикации должна начинаться с https://.',
        ]);
    }
}

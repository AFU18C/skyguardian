<?php

namespace App\Http\Controllers;

use App\Models\AlertSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class AlertSourceController extends Controller
{
    public function index(): View
    {
        return view('pages.alerts.sources', [
            'sources' => AlertSource::query()->latest()->get(),
        ]);
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
            return response()->json([
                'ok' => false,
                'message' => 'Некорректная ссылка Telegram. Укажите публичный канал вида https://t.me/channel_name.',
            ], 422);
        }

        try {
            $response = Http::accept('text/html,application/xhtml+xml')
                ->withUserAgent('Mozilla/5.0 (compatible; SkyGuardian/0.5; +https://localhost)')
                ->timeout(12)
                ->retry(1, 300, throw: false)
                ->get("https://t.me/s/{$channel}");
        } catch (ConnectionException) {
            return response()->json([
                'ok' => false,
                'message' => 'Не удалось подключиться к Telegram. Проверьте интернет-соединение сервера.',
            ], 503);
        }

        if (! $response->successful()) {
            return response()->json([
                'ok' => false,
                'message' => 'Telegram-канал недоступен или не существует.',
            ], 422);
        }

        $html = $response->body();

        if (! str_contains($html, 'tgme_widget_message')) {
            return response()->json([
                'ok' => false,
                'message' => 'Публикации не найдены. Возможно, канал закрытый, пустой или ссылка неверна.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Источник работает: публикации Telegram доступны.',
        ]);
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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:api,telegram,website'],
            'address' => ['required', 'url', 'max:2048'],
            'publication_chat' => ['required', 'url', 'max:2048'],
            'check_interval' => ['required', 'integer', 'min:1', 'max:1440'],
        ], [
            'address.url' => 'Укажите корректную HTTPS-ссылку.',
            'publication_chat.url' => 'Укажите корректную HTTPS-ссылку Telegram.',
        ]);

        if (! str_starts_with(strtolower($data['address']), 'https://')) {
            return $request->validate([
                'address' => ['starts_with:https://'],
            ]);
        }

        if ($data['type'] === 'telegram' && ! preg_match('~^https://t\.me/[A-Za-z0-9_+\-/]+$~i', $data['address'])) {
            $request->validate([
                'address' => [function (string $attribute, mixed $value, \Closure $fail): void {
                    $fail('Для Telegram укажите ссылку вида https://t.me/channel_name.');
                }],
            ]);
        }

        if (! preg_match('~^https://t\.me/[A-Za-z0-9_+\-/]+$~i', $data['publication_chat'])) {
            $request->validate([
                'publication_chat' => [function (string $attribute, mixed $value, \Closure $fail): void {
                    $fail('Укажите ссылку вида https://t.me/channel_name.');
                }],
            ]);
        }

        return $data;
    }
}

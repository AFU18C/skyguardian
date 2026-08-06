<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Models\TechnicalAccount;
use App\Services\SourcePollingSettings;
use App\Services\SourceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SourceController extends Controller
{
    public function index(Request $request, SourcePollingSettings $pollingSettings): View
    {
        $type = $this->type($request);

        return view('admin.sources.index', [
            'type' => $type,
            'title' => $type === Source::TYPE_NEWS ? 'Новости' : 'Воздушная тревога',
            'pollingSettings' => $pollingSettings->get($type),
            'sources' => Source::query()
                ->where('type', $type)
                ->with(['technicalAccount.telegramApi', 'rules'])
                ->latest()
                ->paginate(12),
            'accounts' => TechnicalAccount::query()
                ->with('telegramApi')
                ->orderBy('name')
                ->get(),
            'sourceLimitReached' => Source::query()->count() >= config('skyguardian.limits.sources', 40),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $this->type($request);
        $validated = $this->validateSource($request);

        try {
            DB::transaction(function () use ($validated, $type): void {
                $source = Source::query()->create([
                    ...$this->sourceAttributes($validated),
                    'type' => $type,
                    'is_active' => (bool) ($validated['is_active'] ?? false),
                    'next_check_at' => ($validated['is_active'] ?? false) ? now() : null,
                    'last_message_id' => null,
                    'last_success_at' => null,
                ]);

                $this->saveRules($source, $validated);
            });

            return back()->with('toast', ['type' => 'success', 'title' => 'Источник добавлен', 'message' => 'Будут публиковаться только новые сообщения.']);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('toast', ['type' => 'error', 'title' => 'Ошибка', 'message' => 'Не удалось добавить источник.']);
        }
    }

    public function update(Request $request, Source $source): RedirectResponse
    {
        $this->ensureType($request, $source);
        $validated = $this->validateSource($request);

        try {
            DB::transaction(function () use ($source, $validated): void {
                $sourcePeerChanged = $source->source_peer !== $validated['source_peer'];
                $accountChanged = (string) $source->technical_account_id !== (string) ($validated['technical_account_id'] ?? '');
                $resetCursor = (bool) ($validated['reset_cursor'] ?? false) || $sourcePeerChanged || $accountChanged;

                $attributes = [
                    ...$this->sourceAttributes($validated),
                    'is_active' => (bool) ($validated['is_active'] ?? false),
                    'next_check_at' => ($validated['is_active'] ?? false) ? now() : null,
                ];

                if ($resetCursor) {
                    $attributes['last_message_id'] = null;
                    $attributes['last_success_at'] = null;
                }

                $source->update($attributes);
                $this->saveRules($source, $validated);
            });

            return back()->with('toast', ['type' => 'success', 'title' => 'Изменения сохранены', 'message' => 'Настройки копирования обновлены.']);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('toast', ['type' => 'error', 'title' => 'Ошибка', 'message' => 'Не удалось сохранить изменения.']);
        }
    }

    public function destroy(Request $request, Source $source): RedirectResponse
    {
        $this->ensureType($request, $source);

        try {
            $source->delete();

            return back()->with('toast', ['type' => 'success', 'title' => 'Источник удалён', 'message' => 'Запись удалена из системы.']);
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'title' => 'Ошибка', 'message' => 'Не удалось удалить источник.']);
        }
    }

    public function check(Request $request, Source $source, SourceService $service): RedirectResponse
    {
        $this->ensureType($request, $source);

        try {
            $service->manualCheck($source->load('technicalAccount.telegramApi'));

            return back()->with('toast', ['type' => 'success', 'title' => 'Проверка завершена', 'message' => 'Источник доступен.']);
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'title' => 'Ошибка проверки', 'message' => $this->safeMessage($e)]);
        }
    }

    private function validateSource(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'technical_account_id' => ['nullable', 'integer', 'exists:technical_accounts,id'],
            'source_peer' => ['required', 'string', 'max:255'],
            'destination_peer' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'check_interval' => ['required', 'integer', 'min:1', 'max:86400'],
            'check_interval_unit' => ['required', Rule::in(['seconds', 'minutes', 'hours'])],
            'copy_mode' => ['required', Rule::in(['original', 'text_only'])],
            'strip_links' => ['nullable', 'boolean'],
            'strip_hashtags' => ['nullable', 'boolean'],
            'strip_mentions' => ['nullable', 'boolean'],
            'remove_phrases' => ['nullable', 'string', 'max:10000'],
            'footer_html' => ['nullable', 'string', 'max:10000'],
            'blocked_keywords_enabled' => ['nullable', 'boolean'],
            'blocked_keywords' => ['nullable', 'string', 'max:10000'],
            'map_button_enabled' => ['nullable', 'boolean'],
            'map_button_url' => [
                'nullable',
                'string',
                'max:2048',
                'url',
                'starts_with:http://,https://',
                'required_if:map_button_enabled,1',
            ],
            'reset_cursor' => ['nullable', 'boolean'],
        ]);
    }

    private function sourceAttributes(array $validated): array
    {
        return Arr::only($validated, [
            'name',
            'technical_account_id',
            'source_peer',
            'destination_peer',
            'check_interval',
            'check_interval_unit',
        ]);
    }

    private function saveRules(Source $source, array $validated): void
    {
        $settings = [
            ['key' => 'copy_mode', 'value' => $validated['copy_mode'], 'is_active' => true, 'priority' => 10],
            ['key' => 'strip_links', 'value' => (bool) ($validated['strip_links'] ?? false), 'is_active' => true, 'priority' => 20],
            ['key' => 'strip_hashtags', 'value' => (bool) ($validated['strip_hashtags'] ?? false), 'is_active' => true, 'priority' => 30],
            ['key' => 'strip_mentions', 'value' => (bool) ($validated['strip_mentions'] ?? false), 'is_active' => true, 'priority' => 40],
            ['key' => 'remove_phrases', 'value' => trim((string) ($validated['remove_phrases'] ?? '')), 'is_active' => true, 'priority' => 50],
            ['key' => 'footer_html', 'value' => $this->sanitizeFooterHtml((string) ($validated['footer_html'] ?? '')), 'is_active' => true, 'priority' => 60],
            [
                'key' => 'blocked_keywords',
                'value' => trim((string) ($validated['blocked_keywords'] ?? '')),
                'is_active' => (bool) ($validated['blocked_keywords_enabled'] ?? false),
                'priority' => 70,
            ],
            [
                'key' => 'map_button_url',
                'value' => trim((string) ($validated['map_button_url'] ?? 'https://skyguardian.pp.ua/')),
                'is_active' => (bool) ($validated['map_button_enabled'] ?? false),
                'priority' => 80,
            ],
        ];

        foreach ($settings as $setting) {
            $source->rules()->updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => ['value' => $setting['value']],
                    'is_active' => $setting['is_active'],
                    'priority' => $setting['priority'],
                ],
            );
        }
    }

    private function sanitizeFooterHtml(string $html): string
    {
        $allowedTags = '<b><strong><i><em><u><s><strike><code><pre><blockquote><a><br>';
        $html = strip_tags($html, $allowedTags);

        $html = preg_replace_callback('/<([a-z0-9]+)\b[^>]*>/iu', static function (array $matches): string {
            $tag = mb_strtolower($matches[1]);

            if ($tag === 'br') {
                return '<br>';
            }

            if ($tag !== 'a') {
                return '<'.$tag.'>';
            }

            if (! preg_match('/href\s*=\s*(["\'])(.*?)\1/iu', $matches[0], $hrefMatch)) {
                return '<a>';
            }

            $href = trim($hrefMatch[2]);
            $scheme = mb_strtolower((string) parse_url($href, PHP_URL_SCHEME));

            if (! in_array($scheme, ['http', 'https', 'tg', 'mailto'], true)) {
                return '<a>';
            }

            return '<a href="'.e($href, false).'">';
        }, $html) ?? '';

        return trim($html);
    }

    private function type(Request $request): string
    {
        if ($request->routeIs('admin.news.*')) {
            return Source::TYPE_NEWS;
        }

        if ($request->routeIs('admin.air-alert.*')) {
            return Source::TYPE_AIR_ALERT;
        }

        abort(404);
    }

    private function ensureType(Request $request, Source $source): void
    {
        abort_unless($source->type === $this->type($request), 404);
    }

    private function safeMessage(Throwable $e): string
    {
        $allowed = [
            'Источник не привязан к техническому аккаунту.',
            'Технический аккаунт отключён.',
            'Telethon daemon недоступен.',
        ];

        foreach ($allowed as $message) {
            if (str_contains($e->getMessage(), $message)) {
                return $message;
            }
        }

        return 'Не удалось проверить источник. Подробности записаны в журнал.';
    }
}

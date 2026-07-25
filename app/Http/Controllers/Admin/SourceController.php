<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Models\TechnicalAccount;
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
    public function index(Request $request): View
    {
        $type = $this->type($request);

        return view('admin.sources.index', [
            'type' => $type,
            'title' => $type === Source::TYPE_NEWS ? 'Новости' : 'Воздушная тревога',
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
                    ...Arr::except($validated, ['rules']),
                    'type' => $type,
                    'is_active' => (bool) ($validated['is_active'] ?? false),
                    'next_check_at' => ($validated['is_active'] ?? false) ? now() : null,
                ]);

                $this->saveRules($source, $validated['rules'] ?? []);
            });

            return back()->with('toast', ['type' => 'success', 'title' => 'Источник добавлен', 'message' => 'Настройки источника сохранены.']);
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
                $source->update([
                    ...Arr::except($validated, ['rules']),
                    'is_active' => (bool) ($validated['is_active'] ?? false),
                    'next_check_at' => ($validated['is_active'] ?? false) ? now() : null,
                ]);

                $this->saveRules($source, $validated['rules'] ?? []);
            });

            return back()->with('toast', ['type' => 'success', 'title' => 'Изменения сохранены', 'message' => 'Источник обновлён.']);
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
            'rules' => ['nullable', 'array', 'max:20'],
            'rules.*.key' => ['required', 'string', 'max:64', 'distinct'],
            'rules.*.value' => ['nullable', 'string', 'max:5000'],
            'rules.*.is_active' => ['nullable', 'boolean'],
            'rules.*.priority' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);
    }

    private function saveRules(Source $source, array $rules): void
    {
        $source->rules()->delete();

        foreach ($rules as $index => $rule) {
            $source->rules()->create([
                'key' => $rule['key'],
                'value' => ['value' => $rule['value'] ?? ''],
                'is_active' => (bool) ($rule['is_active'] ?? false),
                'priority' => (int) ($rule['priority'] ?? (($index + 1) * 100)),
            ]);
        }
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

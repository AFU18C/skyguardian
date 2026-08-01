<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Source;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $sources = Source::query()
            ->with('technicalAccount:id,status,is_active')
            ->get()
            ->groupBy('type');

        return view('admin.dashboard', [
            'sourceHealth' => [
                $this->healthFor($sources->get(Source::TYPE_NEWS, collect()), 'Новости'),
                $this->healthFor($sources->get(Source::TYPE_AIR_ALERT, collect()), 'Воздушные тревоги'),
            ],
        ]);
    }

    private function healthFor(Collection $sources, string $label): array
    {
        if ($sources->isEmpty()) {
            return [
                'label' => $label,
                'state' => 'missing',
                'status' => 'Не настроено',
                'description' => 'Источник ещё не добавлен.',
                'last_success_at' => null,
            ];
        }

        $active = $sources->where('is_active', true)->values();
        if ($active->isEmpty()) {
            return [
                'label' => $label,
                'state' => 'disabled',
                'status' => 'Отключено',
                'description' => 'Все источники этого типа выключены.',
                'last_success_at' => $sources->max('last_success_at'),
            ];
        }

        $hasConnectionProblem = $active->contains(function (Source $source): bool {
            return ! $source->technicalAccount
                || ! $source->technicalAccount->is_active
                || $source->technicalAccount->status !== 'connected';
        });
        $hasSourceError = $active->contains(fn (Source $source): bool => filled($source->last_error));
        $hasStaleSource = $active->contains(function (Source $source): bool {
            if (! $source->last_success_at) {
                return true;
            }

            $seconds = match ($source->check_interval_unit) {
                'minutes' => $source->check_interval * 60,
                'hours' => $source->check_interval * 3600,
                default => $source->check_interval,
            };

            return $source->last_success_at->lt(now()->subSeconds(max(300, $seconds * 3)));
        });

        $latestSuccess = $active
            ->filter(fn (Source $source): bool => $source->last_success_at !== null)
            ->max('last_success_at');

        if ($hasConnectionProblem || $hasSourceError) {
            return [
                'label' => $label,
                'state' => 'critical',
                'status' => 'Требует внимания',
                'description' => $hasSourceError
                    ? 'У одного из источников сохранена ошибка обработки.'
                    : 'Технический аккаунт не подключён или отключён.',
                'last_success_at' => $latestSuccess,
            ];
        }

        if ($hasStaleSource) {
            return [
                'label' => $label,
                'state' => 'warning',
                'status' => 'Проверка задерживается',
                'description' => 'Последняя успешная обработка была позже ожидаемого.',
                'last_success_at' => $latestSuccess,
            ];
        }

        return [
            'label' => $label,
            'state' => 'healthy',
            'status' => 'Работает',
            'description' => $active->count() === 1
                ? 'Источник активен и обрабатывается планировщиком.'
                : 'Активные источники обрабатываются планировщиком.',
            'last_success_at' => $latestSuccess,
        ];
    }
}

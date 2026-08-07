<?php

namespace App\Services;

use App\Models\Source;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class SourceScheduler
{
    public function due(int $limit = 40, ?string $type = null): Collection
    {
        return Source::query()
            ->with('technicalAccount.telegramApi')
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->due()
            ->orderByRaw('next_check_at IS NULL DESC')
            ->orderBy('next_check_at')
            ->limit($limit)
            ->get();
    }

    public function scheduleNext(Source $source, ?CarbonInterface $from = null): void
    {
        $from ??= now();
        $amount = max(1, $source->check_interval);

        $next = match ($source->check_interval_unit) {
            'minutes' => $from->copy()->addMinutes($amount),
            'hours' => $from->copy()->addHours($amount),
            default => $from->copy()->addSeconds($amount),
        };

        $source->forceFill(['next_check_at' => $next])->save();
    }
}

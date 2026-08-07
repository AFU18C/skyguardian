<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Services\SourcePollingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SourcePollingSettingsController extends Controller
{
    public function __invoke(Request $request, SourcePollingSettings $settings): RedirectResponse
    {
        $type = (string) $request->route('sourceType');

        abort_unless(in_array($type, [Source::TYPE_NEWS, Source::TYPE_AIR_ALERT], true), 404);

        $unit = (string) $request->input('polling_interval_unit', SourcePollingSettings::DEFAULT_INTERVAL_UNIT);
        [$min, $max] = match ($unit) {
            'seconds' => [SourcePollingSettings::MIN_INTERVAL_SECONDS, SourcePollingSettings::MAX_INTERVAL_SECONDS],
            'hours' => [1, 24],
            default => [1, 1440],
        };

        $data = $request->validate([
            'polling_enabled' => ['nullable', 'boolean'],
            'polling_interval_value' => ['required', 'integer', 'min:'.$min, 'max:'.$max],
            'polling_interval_unit' => ['required', Rule::in(['seconds', 'minutes', 'hours'])],
            'source_type' => ['required', Rule::in([$type])],
        ]);

        $settings->update(
            $type,
            $request->boolean('polling_enabled'),
            (int) $data['polling_interval_value'],
            (string) $data['polling_interval_unit'],
        );

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Настройки сохранены',
            'message' => $type === Source::TYPE_NEWS
                ? 'Частота проверки новостей обновлена.'
                : 'Частота проверки источников воздушных тревог обновлена.',
        ]);
    }
}

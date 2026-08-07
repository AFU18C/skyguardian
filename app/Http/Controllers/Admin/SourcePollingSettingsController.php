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

        $data = $request->validate([
            'polling_enabled' => ['nullable', 'boolean'],
            'polling_interval_minutes' => [
                'required',
                'integer',
                'min:'.SourcePollingSettings::MIN_INTERVAL_MINUTES,
                'max:'.SourcePollingSettings::MAX_INTERVAL_MINUTES,
            ],
            'source_type' => ['required', Rule::in([$type])],
        ]);

        $settings->update(
            $type,
            $request->boolean('polling_enabled'),
            (int) $data['polling_interval_minutes'],
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

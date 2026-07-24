<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\TelegramAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewsChannelsController extends Controller
{
    public function index(): View
    {
        return view('admin.news-channels', [
            'channels' => Source::query()
                ->with('telegramAccount')
                ->latest()
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.news-channel-form', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['type'] = 'telegram';
        $data['is_active'] = true;
        $data['remove_links'] = true;
        $data['remove_hashtags'] = true;

        Source::query()->create($data);

        return redirect()->route('news.channels')->with('status', 'Канал данных добавлен.');
    }

    public function edit(Source $channel): View
    {
        return view('admin.news-channel-form', $this->formData($channel));
    }

    public function update(Request $request, Source $channel): RedirectResponse
    {
        $data = $this->validated($request, $channel);
        $data['remove_links'] = true;
        $data['remove_hashtags'] = true;

        $channel->update($data);

        return redirect()->route('news.channels')->with('status', 'Канал данных сохранён.');
    }

    public function toggle(Source $channel): RedirectResponse
    {
        $channel->update(['is_active' => ! $channel->is_active]);

        return redirect()->route('news.channels')->with('status', 'Статус канала изменён.');
    }

    public function destroy(Source $channel): RedirectResponse
    {
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
            'accounts' => TelegramAccount::query()->orderBy('name')->get(),
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
                Rule::unique('sources', 'identifier')->ignore($channel),
            ],
            'telegram_account_id' => ['required', 'exists:telegram_accounts,id'],
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

        return $data;
    }
}

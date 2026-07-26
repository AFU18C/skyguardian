<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use App\Models\GroupChannelPublication;
use App\Services\GroupChannelPublicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class GroupChannelPublicationController extends Controller
{
    public function store(
        Request $request,
        GroupChannelBot $groupChannelBot,
        GroupChannelPublicationService $service,
    ): RedirectResponse {
        if (! $groupChannelBot->moduleEnabled('publications')) {
            return back()->with('toast', [
                'type' => 'error',
                'title' => 'Публикации выключены',
                'message' => 'Сначала включите модуль публикаций для этого чата.',
            ]);
        }

        $data = $request->validate([
            'text' => ['required', 'string', 'max:4096'],
            'action' => ['required', Rule::in(['draft', 'schedule', 'send'])],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        if ($data['action'] === 'schedule' && empty($data['scheduled_at'])) {
            return back()->withErrors([
                'scheduled_at' => 'Укажите дату и время отправки.',
            ])->withInput();
        }

        $publication = $groupChannelBot->publications()->create([
            'text' => $data['text'],
            'status' => match ($data['action']) {
                'schedule' => GroupChannelPublication::STATUS_SCHEDULED,
                default => GroupChannelPublication::STATUS_DRAFT,
            },
            'scheduled_at' => $data['action'] === 'schedule' ? $data['scheduled_at'] : null,
        ]);

        if ($data['action'] === 'send') {
            try {
                $service->send($publication);
            } catch (Throwable $e) {
                report($e);

                return back()->with('toast', [
                    'type' => 'error',
                    'title' => 'Ошибка публикации',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return back()->with('toast', [
            'type' => 'success',
            'title' => match ($data['action']) {
                'send' => 'Опубликовано',
                'schedule' => 'Запланировано',
                default => 'Черновик сохранён',
            },
            'message' => match ($data['action']) {
                'send' => 'Сообщение отправлено в Telegram.',
                'schedule' => 'Сообщение будет отправлено в выбранное время.',
                default => 'Публикация сохранена без отправки.',
            },
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use App\Models\GroupChannelPublication;
use App\Services\GroupChannelPublicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
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
            'type' => ['required', Rule::in(GroupChannelPublication::TYPES)],
            'text' => ['nullable', 'string', 'max:4096'],
            'media' => ['nullable', 'array', 'max:10'],
            'media.*' => ['file', 'max:51200'],
            'buttons_text' => ['nullable', 'string', 'max:5000'],
            'reactions_text' => ['nullable', 'string', 'max:255'],
            'poll_question' => ['nullable', 'string', 'max:300'],
            'poll_options' => ['nullable', 'string', 'max:4000'],
            'poll_type' => ['nullable', Rule::in(['regular', 'quiz'])],
            'poll_correct_option_id' => ['nullable', 'integer', 'min:0', 'max:9'],
            'poll_open_period' => ['nullable', 'integer', 'min:5', 'max:600'],
            'poll_is_anonymous' => ['nullable', 'boolean'],
            'disable_notification' => ['nullable', 'boolean'],
            'action' => ['required', Rule::in(['preview', 'draft', 'schedule', 'send'])],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'delete_after_value' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'delete_after_unit' => ['nullable', Rule::in(['minutes', 'hours'])],
        ]);

        $this->validateActionModules($groupChannelBot, $data['action']);
        $this->validateContent($data, $request);

        if ($data['action'] === 'schedule' && empty($data['scheduled_at'])) {
            return back()->withErrors([
                'scheduled_at' => 'Укажите дату и время отправки.',
            ])->withInput();
        }

        $deleteAfterMinutes = $this->deleteAfterMinutes($groupChannelBot, $data);
        $buttons = $this->parseButtons($data['buttons_text'] ?? null);
        $reactions = $this->parseList($data['reactions_text'] ?? null);
        $poll = $data['type'] === GroupChannelPublication::TYPE_POLL
            ? $this->pollData($data)
            : null;

        if ($data['action'] === 'preview') {
            return back()->with('group_channel_publication_preview', [
                'bot_id' => $groupChannelBot->id,
                'type' => $data['type'],
                'text' => $data['text'] ?? '',
                'files' => collect($request->file('media', []))->map->getClientOriginalName()->all(),
                'buttons' => $buttons,
                'reactions' => $reactions,
                'poll' => $poll,
            ]);
        }

        $mediaPaths = collect($request->file('media', []))
            ->map(fn ($file): array => [
                'path' => $file->store('group-channel-publications/'.$groupChannelBot->id),
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
            ])
            ->values()
            ->all();

        $publication = $groupChannelBot->publications()->create([
            'type' => $data['type'],
            'text' => $data['text'] ?? '',
            'media_paths' => $mediaPaths,
            'buttons' => $buttons,
            'reactions' => $reactions,
            'poll' => $poll,
            'disable_notification' => (bool) ($data['disable_notification'] ?? false),
            'status' => match ($data['action']) {
                'schedule' => GroupChannelPublication::STATUS_SCHEDULED,
                default => GroupChannelPublication::STATUS_DRAFT,
            },
            'scheduled_at' => $data['action'] === 'schedule' ? $data['scheduled_at'] : null,
            'delete_after_minutes' => $deleteAfterMinutes,
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
                'send' => 'Публикация отправлена в Telegram.',
                'schedule' => 'Публикация будет отправлена в выбранное время.',
                default => 'Публикация сохранена без отправки.',
            },
        ]);
    }

    public function send(
        GroupChannelBot $groupChannelBot,
        GroupChannelPublication $publication,
        GroupChannelPublicationService $service,
    ): RedirectResponse {
        $this->ensurePublicationBelongsToBot($groupChannelBot, $publication);

        try {
            $service->send($publication);

            return back()->with('toast', [
                'type' => 'success',
                'title' => 'Опубликовано',
                'message' => 'Черновик отправлен в Telegram.',
            ]);
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', [
                'type' => 'error',
                'title' => 'Ошибка публикации',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function destroy(
        GroupChannelBot $groupChannelBot,
        GroupChannelPublication $publication,
    ): RedirectResponse {
        $this->ensurePublicationBelongsToBot($groupChannelBot, $publication);

        foreach ($publication->media_paths ?? [] as $media) {
            $path = is_array($media) ? ($media['path'] ?? null) : $media;
            if ($path) {
                Storage::disk('local')->delete($path);
            }
        }

        $publication->delete();

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Удалено',
            'message' => 'Публикация удалена из SkyGuardian.',
        ]);
    }

    private function validateActionModules(GroupChannelBot $bot, string $action): void
    {
        if ($action === 'draft' && ! $bot->moduleEnabled('drafts')) {
            throw new RuntimeException('Модуль черновиков выключен для этого чата.');
        }

        if ($action === 'schedule' && ! $bot->moduleEnabled('scheduled_publications')) {
            throw new RuntimeException('Модуль отложенных публикаций выключен для этого чата.');
        }
    }

    private function validateContent(array $data, Request $request): void
    {
        $type = $data['type'];
        $files = $request->file('media', []);

        if ($type === GroupChannelPublication::TYPE_TEXT && trim((string) ($data['text'] ?? '')) === '') {
            throw new RuntimeException('Введите текст публикации.');
        }

        if (in_array($type, [
            GroupChannelPublication::TYPE_PHOTO,
            GroupChannelPublication::TYPE_VIDEO,
            GroupChannelPublication::TYPE_DOCUMENT,
        ], true) && count($files) !== 1) {
            throw new RuntimeException('Для выбранного типа нужен один файл.');
        }

        if ($type === GroupChannelPublication::TYPE_ALBUM && (count($files) < 2 || count($files) > 10)) {
            throw new RuntimeException('Альбом должен содержать от 2 до 10 файлов.');
        }

        if ($type === GroupChannelPublication::TYPE_POLL) {
            $options = $this->parseList($data['poll_options'] ?? null);
            if (trim((string) ($data['poll_question'] ?? '')) === '' || count($options) < 2) {
                throw new RuntimeException('Укажите вопрос и минимум два варианта ответа.');
            }
        }
    }

    private function deleteAfterMinutes(GroupChannelBot $bot, array $data): ?int
    {
        if (empty($data['delete_after_value'])) {
            return null;
        }

        if (! $bot->moduleEnabled('auto_delete_publications')) {
            throw new RuntimeException('Сначала включите модуль автоудаления публикаций.');
        }

        $minutes = (int) $data['delete_after_value'];

        return ($data['delete_after_unit'] ?? 'minutes') === 'hours' ? $minutes * 60 : $minutes;
    }

    private function pollData(array $data): array
    {
        return [
            'question' => trim((string) ($data['poll_question'] ?? '')),
            'options' => $this->parseList($data['poll_options'] ?? null),
            'type' => $data['poll_type'] ?? 'regular',
            'is_anonymous' => (bool) ($data['poll_is_anonymous'] ?? false),
            'correct_option_id' => (int) ($data['poll_correct_option_id'] ?? 0),
            'open_period' => isset($data['poll_open_period']) ? (int) $data['poll_open_period'] : null,
        ];
    }

    private function parseButtons(?string $value): array
    {
        return collect(preg_split('/\R/u', (string) $value))
            ->map(function (string $line): array {
                [$text, $target] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
                if ($text === '' || $target === '') {
                    return [];
                }

                return [[
                    'text' => $text,
                    str_starts_with($target, 'callback:') ? 'callback_data' : 'url' => str_starts_with($target, 'callback:') ? substr($target, 9) : $target,
                ]];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function parseList(?string $value): array
    {
        return collect(preg_split('/[\r\n,]+/u', (string) $value))
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->values()
            ->all();
    }

    private function ensurePublicationBelongsToBot(
        GroupChannelBot $bot,
        GroupChannelPublication $publication,
    ): void {
        abort_unless($publication->group_channel_bot_id === $bot->id, 404);
    }
}

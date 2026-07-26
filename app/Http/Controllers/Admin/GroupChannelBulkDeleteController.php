<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use App\Models\GroupChannelMessage;
use App\Services\GroupChannelTelegramService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class GroupChannelBulkDeleteController extends Controller
{
    public function __construct(private readonly GroupChannelTelegramService $telegram) {}

    public function preview(Request $request, GroupChannelBot $groupChannelBot): RedirectResponse
    {
        abort_unless($groupChannelBot->moduleEnabled('bulk_delete'), 403);

        $data = $request->validate([
            'mode' => ['required', Rule::in(['last', 'period', 'user', 'links', 'forbidden'])],
            'count' => ['nullable', Rule::in(['10', '50', '100', 10, 50, 100])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'user_id' => ['nullable', 'string', 'max:64'],
            'forbidden_word' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['mode'] === 'user' && empty($data['user_id'])) {
            return back()->with('toast', [
                'type' => 'error',
                'title' => 'Не указан пользователь',
                'message' => 'Введите Telegram ID пользователя.',
            ]);
        }

        $query = $groupChannelBot->messages()
            ->whereNull('deleted_at_telegram');
        $this->applyCriteria($query, $groupChannelBot, $data);

        $limit = $data['mode'] === 'last' ? (int) ($data['count'] ?? 10) : 1000;
        $messages = $query
            ->latest('telegram_created_at')
            ->limit($limit)
            ->get(['id', 'telegram_message_id']);
        $token = Str::random(40);
        $preview = [
            'token' => $token,
            'bot_id' => $groupChannelBot->id,
            'message_ids' => $messages->pluck('telegram_message_id')->all(),
            'count' => $messages->count(),
            'mode' => $data['mode'],
        ];
        $request->session()->put('group_channel_bulk_delete', $preview);

        return back()->with('group_channel_bulk_delete_preview', $preview);
    }

    public function execute(Request $request, GroupChannelBot $groupChannelBot): RedirectResponse
    {
        abort_unless($groupChannelBot->moduleEnabled('bulk_delete'), 403);
        $data = $request->validate(['token' => ['required', 'string', 'size:40']]);
        $preview = $request->session()->pull('group_channel_bulk_delete');

        abort_unless(
            is_array($preview)
            && (int) ($preview['bot_id'] ?? 0) === $groupChannelBot->id
            && hash_equals((string) ($preview['token'] ?? ''), $data['token']),
            419,
        );

        $deleted = 0;
        $errors = 0;

        foreach ($preview['message_ids'] ?? [] as $messageId) {
            try {
                $this->telegram->request($groupChannelBot, 'deleteMessage', [
                    'chat_id' => $groupChannelBot->chat_id,
                    'message_id' => $messageId,
                ]);
                GroupChannelMessage::query()
                    ->where('group_channel_bot_id', $groupChannelBot->id)
                    ->where('telegram_message_id', (string) $messageId)
                    ->update(['deleted_at_telegram' => now()]);
                $deleted++;
            } catch (Throwable $e) {
                report($e);
                $errors++;
            }
        }

        return back()->with('toast', [
            'type' => $errors > 0 ? 'warning' : 'success',
            'title' => 'Массовое удаление завершено',
            'message' => "Удалено: {$deleted}. Ошибок: {$errors}.",
        ]);
    }

    private function applyCriteria(Builder $query, GroupChannelBot $bot, array $data): void
    {
        match ($data['mode']) {
            'period' => $query
                ->when($data['date_from'] ?? null, fn (Builder $builder, string $date) => $builder->where('telegram_created_at', '>=', $date))
                ->when($data['date_to'] ?? null, fn (Builder $builder, string $date) => $builder->where('telegram_created_at', '<=', $date)),
            'user' => $query->where('telegram_user_id', (string) ($data['user_id'] ?? '')),
            'links' => $query->where('has_link', true),
            'forbidden' => $this->applyForbiddenWords($query, $bot, $data['forbidden_word'] ?? null),
            default => null,
        };
    }

    private function applyForbiddenWords(Builder $query, GroupChannelBot $bot, ?string $word): void
    {
        $words = collect($word
            ? [trim($word)]
            : $bot->moduleSetting('antispam', 'forbidden_words', []))
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->values()
            ->all();

        if ($words === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $builder) use ($words): void {
            foreach ($words as $forbiddenWord) {
                $builder->orWhere('text', 'like', '%'.$forbiddenWord.'%');
            }
        });
    }
}

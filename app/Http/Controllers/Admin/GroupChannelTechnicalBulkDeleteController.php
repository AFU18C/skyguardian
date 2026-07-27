<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use App\Models\GroupChannelTechnicalDeleteTask;
use App\Models\TechnicalAccount;
use App\Services\GroupChannelTelethonClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class GroupChannelTechnicalBulkDeleteController extends Controller
{
    public function __construct(private readonly GroupChannelTelethonClient $telethon) {}

    public function preview(Request $request, GroupChannelBot $groupChannelBot): RedirectResponse
    {
        abort_unless($groupChannelBot->moduleEnabled('technical_account_bulk_delete'), 403);

        $data = $request->validate([
            'technical_account_id' => ['required', 'integer', 'exists:technical_accounts,id'],
            'mode' => ['required', Rule::in(['all', 'last', 'period'])],
            'count' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        if ($data['mode'] === 'last' && empty($data['count'])) {
            return $this->backToManagement($groupChannelBot, [
                'type' => 'error',
                'title' => 'Не указано количество',
                'message' => 'Укажите, сколько последних сообщений нужно удалить.',
            ]);
        }

        if ($data['mode'] === 'period' && empty($data['date_from']) && empty($data['date_to'])) {
            return $this->backToManagement($groupChannelBot, [
                'type' => 'error',
                'title' => 'Не указан период',
                'message' => 'Укажите хотя бы начало или конец периода.',
            ]);
        }

        $account = TechnicalAccount::query()
            ->with('telegramApi')
            ->findOrFail($data['technical_account_id']);
        $criteria = $this->criteria($data);

        try {
            $result = $this->telethon->call(
                'group_channel_bulk_count',
                $account,
                [
                    'peer' => $groupChannelBot->group_link,
                    ...$criteria,
                ],
                180,
            );

            $token = Str::random(40);
            $preview = [
                'token' => $token,
                'bot_id' => $groupChannelBot->id,
                'technical_account_id' => $account->id,
                'technical_account_name' => $account->name,
                'criteria' => $criteria,
                'count' => (int) ($result['count'] ?? 0),
            ];

            $request->session()->put('group_channel_technical_delete', $preview);

            return back()->with('group_channel_technical_delete_preview', $preview);
        } catch (Throwable $e) {
            report($e);

            return $this->backToManagement($groupChannelBot, [
                'type' => 'error',
                'title' => 'Не удалось прочитать историю',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function execute(Request $request, GroupChannelBot $groupChannelBot): RedirectResponse
    {
        abort_unless($groupChannelBot->moduleEnabled('technical_account_bulk_delete'), 403);

        $data = $request->validate([
            'token' => ['required', 'string', 'size:40'],
        ]);
        $preview = $request->session()->pull('group_channel_technical_delete');

        abort_unless(
            is_array($preview)
            && (int) ($preview['bot_id'] ?? 0) === $groupChannelBot->id
            && hash_equals((string) ($preview['token'] ?? ''), $data['token']),
            419,
        );

        if ((int) ($preview['count'] ?? 0) === 0) {
            return $this->backToManagement($groupChannelBot, [
                'type' => 'info',
                'title' => 'Удалять нечего',
                'message' => 'По выбранным условиям сообщения не найдены.',
            ]);
        }

        GroupChannelTechnicalDeleteTask::query()->create([
            'group_channel_bot_id' => $groupChannelBot->id,
            'technical_account_id' => $preview['technical_account_id'],
            'technical_account_name' => $preview['technical_account_name'],
            'mode' => data_get($preview, 'criteria.mode'),
            'criteria' => $preview['criteria'],
            'matched_count' => $preview['count'],
            'status' => GroupChannelTechnicalDeleteTask::STATUS_PENDING,
        ]);

        return $this->backToManagement($groupChannelBot, [
            'type' => 'success',
            'title' => 'Удаление запущено',
            'message' => 'Задача передана отдельному процессу. Новости и тревоги продолжают работать независимо.',
        ]);
    }

    private function criteria(array $data): array
    {
        return [
            'mode' => $data['mode'],
            'count' => $data['mode'] === 'last' ? (int) $data['count'] : null,
            'date_from' => $this->date($data['date_from'] ?? null),
            'date_to' => $this->date($data['date_to'] ?? null),
        ];
    }

    private function date(?string $value): ?string
    {
        return filled($value)
            ? CarbonImmutable::parse($value, 'Europe/Kyiv')->utc()->toIso8601String()
            : null;
    }

    private function backToManagement(GroupChannelBot $bot, array $toast): RedirectResponse
    {
        return back()->with([
            'toast' => $toast,
            'open_group_channel_manage' => $bot->id,
            'open_group_channel_module' => 'technical_account_bulk_delete',
            'open_group_channel_scroll' => '#group-channel-module-technical_account_bulk_delete-'.$bot->id,
        ]);
    }
}

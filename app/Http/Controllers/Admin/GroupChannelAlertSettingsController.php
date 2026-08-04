<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use App\Services\GroupChannelAlertPublicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GroupChannelAlertSettingsController extends Controller
{
    public function __invoke(
        Request $request,
        GroupChannelBot $groupChannelBot,
        GroupChannelAlertPublicationService $publications,
    ): RedirectResponse {
        abort_unless($groupChannelBot->moduleEnabled(GroupChannelBot::MODULE_ALERT_PUBLICATIONS), 403);

        $data = $request->validate([
            'all_ukraine' => ['nullable', 'boolean'],
            'region_uids' => ['nullable', 'array'],
            'region_uids.*' => ['string', Rule::in(array_map('strval', array_keys(GroupChannelBot::ALERT_REGIONS)))],
            'alert_types' => ['required', 'array', 'min:1'],
            'alert_types.*' => ['string', Rule::in(array_keys(GroupChannelBot::ALERT_TYPES))],
            'publish_start' => ['nullable', 'boolean'],
            'publish_end' => ['nullable', 'boolean'],
            'disable_notification' => ['nullable', 'boolean'],
            'start_template' => ['required', 'string', 'max:3500'],
            'end_template' => ['required', 'string', 'max:3500'],
        ]);

        $allUkraine = $request->boolean('all_ukraine');
        $regionUids = array_values(array_unique(array_map(
            'strval',
            $data['region_uids'] ?? [],
        )));

        if (! $allUkraine && $regionUids === []) {
            throw ValidationException::withMessages([
                'region_uids' => 'Выберите минимум одну область или включите «Вся Украина».',
            ]);
        }

        $settings = array_replace_recursive(
            GroupChannelBot::defaultModuleSettings(),
            $groupChannelBot->module_settings ?? [],
        );
        $previousScope = [
            'all_ukraine' => (bool) data_get(
                $settings,
                GroupChannelBot::MODULE_ALERT_PUBLICATIONS.'.all_ukraine',
                true,
            ),
            'region_uids' => array_values(array_map('strval', (array) data_get(
                $settings,
                GroupChannelBot::MODULE_ALERT_PUBLICATIONS.'.region_uids',
                [],
            ))),
            'alert_types' => array_values(array_map('strval', (array) data_get(
                $settings,
                GroupChannelBot::MODULE_ALERT_PUBLICATIONS.'.alert_types',
                [],
            ))),
        ];
        $incoming = [
            'enabled' => true,
            'all_ukraine' => $allUkraine,
            'region_uids' => $regionUids,
            'alert_types' => array_values(array_unique(array_map('strval', $data['alert_types']))),
            'publish_start' => $request->boolean('publish_start'),
            'publish_end' => $request->boolean('publish_end'),
            'disable_notification' => $request->boolean('disable_notification'),
            'start_template' => trim($data['start_template']),
            'end_template' => trim($data['end_template']),
        ];

        if (! $incoming['publish_start'] && ! $incoming['publish_end']) {
            throw ValidationException::withMessages([
                'publish_start' => 'Включите публикацию тревоги, отбоя или оба события.',
            ]);
        }

        $settings[GroupChannelBot::MODULE_ALERT_PUBLICATIONS] = array_replace_recursive(
            $settings[GroupChannelBot::MODULE_ALERT_PUBLICATIONS] ?? [],
            $incoming,
        );
        $groupChannelBot->update(['module_settings' => $settings]);

        $nextScope = [
            'all_ukraine' => $incoming['all_ukraine'],
            'region_uids' => $incoming['region_uids'],
            'alert_types' => $incoming['alert_types'],
        ];

        if ($this->scopeChanged($previousScope, $nextScope)) {
            $publications->resetBaseline($groupChannelBot->fresh());
        }

        return back()->with([
            'toast' => [
                'type' => 'success',
                'title' => 'Настройки сохранены',
                'message' => 'Публикация тревог настроена для этого канала.',
            ],
            'open_group_channel_manage' => $groupChannelBot->id,
            'open_group_channel_module' => GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            'open_group_channel_scroll' => '#group-channel-module-'.GroupChannelBot::MODULE_ALERT_PUBLICATIONS.'-'.$groupChannelBot->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $next
     */
    private function scopeChanged(array $previous, array $next): bool
    {
        foreach (['region_uids', 'alert_types'] as $key) {
            sort($previous[$key]);
            sort($next[$key]);
        }

        return $previous !== $next;
    }
}

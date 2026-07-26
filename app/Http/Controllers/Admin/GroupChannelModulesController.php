<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use App\Services\GroupChannelWebhookRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class GroupChannelModulesController extends Controller
{
    private const WEBHOOK_MODULES = [
        'bulk_delete',
        'antispam',
        'welcome',
        'subscription_check',
        'join_requests',
        'human_verification',
        'warnings',
        'newcomer_restrictions',
        'slow_mode',
    ];

    public function __invoke(
        Request $request,
        GroupChannelBot $groupChannelBot,
        GroupChannelWebhookRegistrationService $webhook,
    ): RedirectResponse {
        $validated = $request->validate([
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', Rule::in(array_keys(GroupChannelBot::MODULES))],
        ]);

        $selectedModules = $validated['modules'] ?? [];
        $enabled = array_fill_keys($selectedModules, true);
        $settings = array_replace_recursive(
            GroupChannelBot::defaultModuleSettings(),
            $groupChannelBot->module_settings ?? [],
        );

        foreach (GroupChannelBot::MODULES as $key => $label) {
            $settings[$key]['enabled'] = (bool) ($enabled[$key] ?? false);
        }

        $groupChannelBot->update(['module_settings' => $settings]);

        $toast = [
            'type' => 'success',
            'title' => 'Функции сохранены',
            'message' => 'Для этого чата применён выбранный набор функций.',
        ];

        if (array_intersect($selectedModules, self::WEBHOOK_MODULES) !== []) {
            try {
                $webhook->register($groupChannelBot);
                $toast['message'] = 'Функции сохранены. Webhook подключён автоматически.';
            } catch (Throwable $e) {
                report($e);
                $groupChannelBot->update(['webhook_last_error' => $e->getMessage()]);
                $toast = [
                    'type' => 'warning',
                    'title' => 'Функции сохранены',
                    'message' => 'Функции включены, но webhook не подключился: '.$e->getMessage(),
                ];
            }
        }

        return back()->with([
            'toast' => $toast,
            'open_group_channel_manage' => $groupChannelBot->id,
            'open_group_channel_scroll' => '.sg-functions-section',
        ]);
    }
}

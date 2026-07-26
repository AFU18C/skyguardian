<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use App\Services\GroupChannelWebhookRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class GroupChannelModuleToggleController extends Controller
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
        string $module,
        GroupChannelWebhookRegistrationService $webhook,
    ): JsonResponse|RedirectResponse {
        abort_unless(array_key_exists($module, GroupChannelBot::MODULES), 404);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);
        $enabled = (bool) $data['enabled'];

        $settings = array_replace_recursive(
            GroupChannelBot::defaultModuleSettings(),
            $groupChannelBot->module_settings ?? [],
        );
        $settings[$module]['enabled'] = $enabled;
        $groupChannelBot->update(['module_settings' => $settings]);

        $toast = [
            'type' => 'success',
            'title' => $enabled ? 'Функция включена' : 'Функция выключена',
            'message' => GroupChannelBot::MODULES[$module].($enabled ? ' включена.' : ' выключена.'),
        ];

        if ($enabled && in_array($module, self::WEBHOOK_MODULES, true)) {
            try {
                $webhook->register($groupChannelBot);
                $toast['message'] .= ' Webhook подключён автоматически.';
            } catch (Throwable $e) {
                report($e);
                $groupChannelBot->update(['webhook_last_error' => $e->getMessage()]);
                $toast = [
                    'type' => 'warning',
                    'title' => 'Функция включена',
                    'message' => GroupChannelBot::MODULES[$module].' включена, но webhook не подключился: '.$e->getMessage(),
                ];
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'module' => $module,
                'enabled' => $enabled,
                'toast' => $toast,
                'webhook_registered_at' => $groupChannelBot->fresh()->webhook_registered_at?->toIso8601String(),
            ]);
        }

        return back()->with([
            'toast' => $toast,
            'open_group_channel_manage' => $groupChannelBot->id,
            'open_group_channel_module' => $enabled ? $module : null,
            'open_group_channel_scroll' => '#group-channel-module-'.$module.'-'.$groupChannelBot->id,
        ]);
    }
}

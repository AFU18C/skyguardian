<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GroupChannelModuleSettingsController extends Controller
{
    private const CONFIGURABLE_MODULES = [
        'antispam',
        'welcome',
        'subscription_check',
        'join_requests',
        'human_verification',
        'warnings',
        'newcomer_restrictions',
        'slow_mode',
    ];

    public function __invoke(Request $request, GroupChannelBot $groupChannelBot): RedirectResponse
    {
        $data = $request->validate([
            'module' => ['required', Rule::in(self::CONFIGURABLE_MODULES)],
            'settings.antispam.delete_links' => ['nullable', 'boolean'],
            'settings.antispam.delete_new_member_messages' => ['nullable', 'boolean'],
            'settings.antispam.new_member_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'settings.antispam.forbidden_words_text' => ['nullable', 'string', 'max:10000'],
            'settings.antispam.message_limit' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'settings.antispam.message_limit_period_seconds' => ['nullable', 'integer', 'min:5', 'max:86400'],
            'settings.antispam.block_duplicates' => ['nullable', 'boolean'],
            'settings.antispam.max_mentions' => ['nullable', 'integer', 'min:0', 'max:100'],
            'settings.antispam.delete_short_messages' => ['nullable', 'boolean'],
            'settings.antispam.min_length' => ['nullable', 'integer', 'min:1', 'max:100'],
            'settings.antispam.suspicious_symbols' => ['nullable', 'boolean'],
            'settings.welcome.text' => ['nullable', 'string', 'max:4096'],
            'settings.welcome.rules' => ['nullable', 'string', 'max:4096'],
            'settings.welcome.buttons_text' => ['nullable', 'string', 'max:5000'],
            'settings.welcome.delete_after_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'settings.subscription_check.channels_text' => ['nullable', 'string', 'max:10000'],
            'settings.join_requests.auto_approve' => ['nullable', 'boolean'],
            'settings.join_requests.auto_decline_bots' => ['nullable', 'boolean'],
            'settings.human_verification.mode' => ['nullable', Rule::in(['button', 'question', 'captcha'])],
            'settings.human_verification.question' => ['nullable', 'string', 'max:1000'],
            'settings.human_verification.answer' => ['nullable', 'string', 'max:255'],
            'settings.human_verification.timeout_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'settings.warnings.mute_after' => ['nullable', 'integer', 'min:1', 'max:100'],
            'settings.warnings.mute_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'settings.warnings.ban_after' => ['nullable', 'integer', 'min:1', 'max:100'],
            'settings.newcomer_restrictions.minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'settings.newcomer_restrictions.block_links' => ['nullable', 'boolean'],
            'settings.newcomer_restrictions.block_files' => ['nullable', 'boolean'],
            'settings.newcomer_restrictions.block_messages' => ['nullable', 'boolean'],
            'settings.slow_mode.messages' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'settings.slow_mode.period_seconds' => ['nullable', 'integer', 'min:5', 'max:86400'],
        ]);

        $module = $data['module'];
        abort_unless($groupChannelBot->moduleEnabled($module), 403);

        $incoming = data_get($data, 'settings.'.$module, []);
        foreach ($this->booleanKeys($module) as $key) {
            $incoming[$key] = $request->boolean("settings.{$module}.{$key}");
        }

        if ($module === 'antispam') {
            $incoming['forbidden_words'] = $this->lines($incoming['forbidden_words_text'] ?? '');
            unset($incoming['forbidden_words_text']);
        }

        if ($module === 'subscription_check') {
            $incoming['channels'] = $this->lines($incoming['channels_text'] ?? '');
            unset($incoming['channels_text']);
        }

        if ($module === 'welcome') {
            $incoming['buttons'] = $this->buttons($incoming['buttons_text'] ?? '');
            unset($incoming['buttons_text']);
        }

        $settings = array_replace_recursive(
            GroupChannelBot::defaultModuleSettings(),
            $groupChannelBot->module_settings ?? [],
        );
        $settings[$module] = array_replace_recursive($settings[$module] ?? [], $incoming);
        $groupChannelBot->update(['module_settings' => $settings]);

        return back()->with([
            'toast' => [
                'type' => 'success',
                'title' => 'Настройки сохранены',
                'message' => 'Параметры функции обновлены только для этого чата.',
            ],
            'open_group_channel_manage' => $groupChannelBot->id,
            'open_group_channel_module' => $module,
            'open_group_channel_scroll' => '#group-channel-module-'.$module.'-'.$groupChannelBot->id,
        ]);
    }

    private function booleanKeys(string $module): array
    {
        return match ($module) {
            'antispam' => [
                'delete_links',
                'delete_new_member_messages',
                'block_duplicates',
                'delete_short_messages',
                'suspicious_symbols',
            ],
            'join_requests' => ['auto_approve', 'auto_decline_bots'],
            'newcomer_restrictions' => ['block_links', 'block_files', 'block_messages'],
            default => [],
        };
    }

    private function lines(?string $value): array
    {
        return collect(preg_split('/[\r\n,]+/u', (string) $value))
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function buttons(?string $value): array
    {
        return collect(preg_split('/\R/u', (string) $value))
            ->map(function (string $line): array {
                [$text, $url] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');

                return $text !== '' && $url !== '' ? [['text' => $text, 'url' => $url]] : [];
            })
            ->filter()
            ->values()
            ->all();
    }
}

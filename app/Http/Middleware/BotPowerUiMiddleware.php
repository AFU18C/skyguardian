<?php

namespace App\Http\Middleware;

use App\Models\AlertBotSetting;
use App\Models\NewsBotSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BotPowerUiMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->isSuccessful()
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            || (! $request->routeIs('alerts.settings') && ! $request->routeIs('news.settings'))) {
            return $response;
        }

        $html = $response->getContent();
        if (! is_string($html) || $html === '') {
            return $response;
        }

        $settings = $request->routeIs('news.settings')
            ? NewsBotSetting::query()->first()
            : AlertBotSetting::query()->first();

        $tokenConfigured = filled($settings?->bot_token);
        $extra = is_array($settings?->extra_settings) ? $settings->extra_settings : [];
        $enabled = $tokenConfigured && (array_key_exists('bot_enabled', $extra)
            ? (bool) $extra['bot_enabled']
            : true);

        if (! str_contains($html, 'name="bot_enabled"')) {
            $switch = '<div class="switch-row"><div class="switch-copy"><strong>Telegram-бот</strong><span>Включено — бот работает. Выключено — полный стоп.</span></div><label class="switch"><input type="checkbox" name="bot_enabled" value="1"'
                .($enabled ? ' checked' : '')
                .(! $tokenConfigured ? ' disabled' : '')
                .'><span class="slider"></span></label></div>';

            $html = preg_replace(
                '/(<div class="field"><label>Название бота<\/label>)/u',
                $switch.'$1',
                $html,
                1,
            ) ?? $html;

            if (! str_contains($html, 'name="bot_enabled"')) {
                $html = preg_replace(
                    '/(<div class="field"><label>(?:Токен Telegram-бота|Токен бота)<\/label>)/u',
                    $switch.'$1',
                    $html,
                    1,
                ) ?? $html;
            }
        }

        $html = str_replace('Удалить токен и отключить бота', 'Удалить токен', $html);
        $html = str_replace('Удалить токен и отключить бота?', 'Удалить токен?', $html);

        $response->setContent($html);

        return $response;
    }
}

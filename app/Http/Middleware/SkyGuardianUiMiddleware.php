<?php

namespace App\Http\Middleware;

use App\Models\AlertBotSetting;
use App\Models\NewsBotSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SkyGuardianUiMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->applySecurityHeaders($response);

        if (! $response->isSuccessful()
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $html = $response->getContent();
        if (! is_string($html) || $html === '') {
            return $response;
        }

        $html = str_replace('>Пользователи<', '>Группа / канал<', $html);
        $html = str_replace('>Управление группой<', '>Группа / канал<', $html);

        if ($request->routeIs('dashboard')) {
            $html = preg_replace('/<section class="metrics">.*?<\/section>/s', '', $html) ?? $html;
            $html = preg_replace('/<div class="system-row"><span>Версия<\/span>.*?<\/div>/s', '', $html) ?? $html;
        }

        if ($request->routeIs('alerts.settings')) {
            $settings = AlertBotSetting::query()->first();
            $html = $this->decorateBotSettings($html, $settings?->bot_name, $settings?->bot_token);
        }

        if ($request->routeIs('news.settings')) {
            $settings = NewsBotSetting::query()->first();
            $html = preg_replace('/<div class="notice">Этот раздел полностью изолирован от воздушной тревоги\..*?<\/div>/s', '', $html) ?? $html;
            $html = str_replace('Параметры новостного бота', 'Telegram-бот', $html);
            $html = $this->reorderNewsAccordions($html);
            $html = $this->decorateBotSettings($html, $settings?->bot_name, $settings?->bot_token);
        }

        $response->setContent($html);

        return $response;
    }

    private function applySecurityHeaders(Response $response): void
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
    }

    private function decorateBotSettings(string $html, ?string $botName, ?string $token): string
    {
        if (! str_contains($html, 'name="bot_name"')) {
            $nameField = '<div class="field"><label>Название бота</label><input class="input" name="bot_name" value="'.e($botName ?? '').'" placeholder="Например: SkyGuardian"></div>';
            $html = preg_replace(
                '/(<div class="field"><label>(?:Токен Telegram-бота|Токен бота)<\/label>)/u',
                $nameField.'$1',
                $html,
                1,
            ) ?? $html;
        }

        $masked = $this->maskToken($token);
        if ($masked === null) {
            return $html;
        }

        $html = preg_replace_callback(
            '/<input class="input" type="password" name="bot_token"([^>]*)>/u',
            function (array $matches) use ($masked): string {
                $attributes = preg_replace('/\s+placeholder="[^"]*"/u', '', $matches[1]) ?? $matches[1];

                return '<input class="input" type="password" name="bot_token"'.$attributes
                    .' placeholder="'.e($masked).' — введите новый токен для замены">';
            },
            $html,
            1,
        ) ?? $html;

        if (! str_contains($html, 'name="remove_bot_token"')) {
            $removeAction = '<div class="actions"><button class="btn danger" type="submit" name="remove_bot_token" value="1" formnovalidate onclick="return confirm(\'Удалить токен и отключить бота?\')">Удалить токен и отключить бота</button></div>';
            $html = preg_replace(
                '/(<div class="field"><label>(?:Токен Telegram-бота|Токен бота)<\/label><input class="input" type="password" name="bot_token"[^>]*><\/div>)/u',
                '$1'.$removeAction,
                $html,
                1,
            ) ?? $html;
        }

        return $html;
    }

    private function reorderNewsAccordions(string $html): string
    {
        if (! preg_match('/<div class="grid">(.*)<\/div><\/div><\/main>/s', $html, $gridMatch)) {
            return $html;
        }

        preg_match_all('/<details class="card".*?<\/details>/s', $gridMatch[1], $matches);
        if (count($matches[0]) < 3) {
            return $html;
        }

        $ordered = [];
        foreach (['Технические аккаунты', 'Telegram API', 'Telegram-бот'] as $title) {
            foreach ($matches[0] as $block) {
                if (str_contains($block, '<h2>'.$title.'</h2>')) {
                    $ordered[] = $block;
                    break;
                }
            }
        }

        if (count($ordered) !== 3) {
            return $html;
        }

        return str_replace($gridMatch[1], implode('', $ordered), $html);
    }

    private function maskToken(?string $token): ?string
    {
        if (! filled($token)) {
            return null;
        }

        $length = mb_strlen($token);
        if ($length <= 8) {
            return mb_substr($token, 0, 2).'****'.mb_substr($token, -2);
        }

        return mb_substr($token, 0, 6).'****'.mb_substr($token, -4);
    }
}

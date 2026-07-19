<?php

namespace App\Http\Middleware;

use App\Models\AlertBotSetting;
use App\Models\NewsBotSetting;
use App\Models\NewsTechnicalTelegramAccount;
use App\Models\TechnicalTelegramAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
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
            $html = $this->showAccountsWithoutApi($html, TechnicalTelegramAccount::query()->orderByDesc('is_primary')->orderBy('id')->get());
            $html = $this->keepTechnicalAccountsOpen($request, $html, 'telegram_auth');
        }

        if ($request->routeIs('news.settings')) {
            $settings = NewsBotSetting::query()->first();
            $html = preg_replace('/<div class="notice">Этот раздел полностью изолирован от воздушной тревоги\..*?<\/div>/s', '', $html) ?? $html;
            $html = str_replace('Параметры новостного бота', 'Telegram-бот', $html);
            $html = $this->reorderNewsAccordions($html);
            $html = $this->decorateBotSettings($html, $settings?->bot_name, $settings?->bot_token);
            $html = $this->showAccountsWithoutApi($html, NewsTechnicalTelegramAccount::query()->orderByDesc('is_primary')->orderBy('id')->get());
            $html = $this->keepTechnicalAccountsOpen($request, $html, 'news_telegram_auth');
        }

        if ($request->routeIs('alerts.sources') || $request->routeIs('news.sources')) {
            $html = $this->stabilizeSourceCards($html);
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

    private function keepTechnicalAccountsOpen(Request $request, string $html, string $authKey): string
    {
        $errors = $request->session()->get('errors');
        $hasErrors = $errors instanceof ViewErrorBag && $errors->any();
        $hasStatus = filled($request->session()->get('status'));

        if (! $request->session()->has($authKey) && ! $hasErrors && ! $hasStatus) {
            return $html;
        }

        return preg_replace('/<details class="card">/', '<details class="card" open>', $html, 1) ?? $html;
    }

    private function stabilizeSourceCards(string $html): string
    {
        $css = <<<'CSS'
<style id="source-card-long-title-fix">
.source-title{min-width:0;max-width:100%;overflow-wrap:anywhere;word-break:normal;line-height:1.25}
.source-head>div:first-child{min-width:0}
.source-account{min-width:0;overflow-wrap:anywhere}
@media(max-width:800px){
.source-head{grid-template-columns:minmax(0,1fr);gap:16px;align-items:start;padding:19px 21px 92px}
.source-account{grid-column:auto}
.source-statuses{grid-column:auto;grid-row:auto;justify-self:start;flex-direction:row;flex-wrap:wrap}
.source-check-form{position:absolute;left:21px;right:82px;bottom:18px;margin:0}
.source-check-btn{width:100%;min-height:42px}
.edit-source-btn{right:18px;bottom:18px}
}
</style>
CSS;

        return str_replace('</head>', $css.'</head>', $html);
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

    private function showAccountsWithoutApi(string $html, $accounts): string
    {
        if ($accounts->isEmpty() || ! str_contains($html, 'Сначала добавьте Telegram API')) {
            return $html;
        }

        $rows = '';
        foreach ($accounts as $account) {
            $label = e($account->label ?: 'Технический аккаунт');
            $phone = e($account->phone ?: 'Номер не указан');
            $telegramId = e($account->telegram_id ?: '—');
            $primary = $account->is_primary ? '<span class="badge">Основной</span>' : '';

            $rows .= '<div class="row"><div class="row-main" style="cursor:default">'
                .'<div><span class="row-name">'.$label.'</span>'.$primary.'</div>'
                .'<div class="row-meta">'.$phone.' · ID '.$telegramId.'<br><strong style="color:#b97810">API не выбран</strong></div>'
                .'<div class="row-status"><span class="status-icon warn">!</span></div>'
                .'</div></div>';
        }

        $replacement = '<div class="notice">Telegram API удалён. Технический аккаунт сохранён. Добавьте новый API и привяжите его к аккаунту для переподключения.</div><div class="list">'.$rows.'</div>';

        return preg_replace(
            '/<div class="empty-state"><strong>Сначала добавьте Telegram API<\/strong><span>.*?<\/span><\/div>/s',
            $replacement,
            $html,
            1,
        ) ?? $html;
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

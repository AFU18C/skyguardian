<?php

namespace App\Http\Middleware;

use App\Models\NewsTechnicalTelegramAccount;
use App\Models\TechnicalTelegramAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TechnicalAccountPowerUiMiddleware
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

        $news = $request->routeIs('news.settings');
        $accounts = $news
            ? NewsTechnicalTelegramAccount::query()->orderByDesc('is_primary')->orderBy('id')->get()
            : TechnicalTelegramAccount::query()->orderByDesc('is_primary')->orderBy('id')->get();
        $section = $news ? 'news' : 'alerts';

        if ($accounts->isEmpty()) {
            return $response;
        }

        if (! preg_match('/(<details class="card"[^>]*>.*?<h2>Технические аккаунты<\/h2>.*?<div class="list">)(.*?)(<\/div>\s*<\/div>\s*<\/details>)/s', $html, $match)) {
            return $response;
        }

        $index = 0;
        $body = preg_replace_callback('/<div class="row-status">.*?<\/div>/s', function () use (&$index, $accounts, $section): string {
            $account = $accounts->get($index++);
            if (! $account) {
                return '<div class="row-status"></div>';
            }

            $checked = $account->status === 'connected' ? ' checked' : '';
            $disabled = (! $account->telegram_id || ! $account->telegramApiCredential) ? ' disabled' : '';

            return '<div class="row-status"><label class="account-power-switch" title="Включить или выключить технический аккаунт">'
                .'<input type="checkbox" data-account-power data-url="/'.$section.'/settings/telegram/'.$account->getKey().'/power"'
                .$checked.$disabled.' aria-label="Включить или выключить технический аккаунт">'
                .'<span></span></label></div>';
        }, $match[2]) ?? $match[2];

        $html = str_replace($match[0], $match[1].$body.$match[3], $html);

        $assets = <<<'HTML'
<style id="technical-account-power-style">
.account-power-switch{position:relative;display:block;width:48px;height:28px;cursor:pointer}
.account-power-switch input{position:absolute;opacity:0;pointer-events:none}
.account-power-switch span{position:absolute;inset:0;border-radius:999px;background:#cbd5e1;box-shadow:inset 0 0 0 1px rgba(15,23,42,.08);transition:.2s}
.account-power-switch span:before{content:"";position:absolute;left:3px;top:3px;width:22px;height:22px;border-radius:50%;background:#fff;box-shadow:0 2px 7px rgba(15,23,42,.24);transition:.2s}
.account-power-switch input:checked+span{background:#18a765}
.account-power-switch input:checked+span:before{transform:translateX(20px)}
.account-power-switch input:disabled+span{opacity:.45;cursor:not-allowed}
</style>
<script id="technical-account-power-script">
document.addEventListener('change',async function(event){
    const toggle=event.target.closest('[data-account-power]');
    if(!toggle)return;
    const previous=!toggle.checked;
    const token=document.querySelector('meta[name="csrf-token"]')?.content||document.querySelector('input[name="_token"]')?.value;
    if(!token||!toggle.dataset.url){toggle.checked=previous;return;}
    toggle.disabled=true;
    try{
        const response=await fetch(toggle.dataset.url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token,'X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({enabled:toggle.checked})});
        const data=await response.json();
        if(!response.ok||!data.ok)throw new Error(data.message||'Не удалось изменить состояние аккаунта.');
        toggle.checked=Boolean(data.enabled);
        if(!data.enabled)location.reload();
    }catch(error){toggle.checked=previous;alert(error.message||'Не удалось изменить состояние аккаунта.');}
    finally{toggle.disabled=false;}
});
</script>
HTML;

        $html = str_replace('</head>', $assets.'</head>', $html);
        $response->setContent($html);

        return $response;
    }
}

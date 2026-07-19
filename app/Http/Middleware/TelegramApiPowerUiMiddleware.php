<?php

namespace App\Http\Middleware;

use App\Models\NewsTelegramApiCredential;
use App\Models\TelegramApiCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TelegramApiPowerUiMiddleware
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
        $section = $news ? 'news' : 'alerts';
        $apis = $news
            ? NewsTelegramApiCredential::query()->orderByDesc('is_primary')->orderBy('id')->get()
            : TelegramApiCredential::query()->orderByDesc('is_primary')->orderBy('id')->get();

        if ($apis->isEmpty()) {
            return $response;
        }

        $telegramApiHeading = mb_strpos($html, '<h2>Telegram API</h2>');
        if ($telegramApiHeading === false) {
            return $response;
        }

        $listStart = mb_strpos($html, '<div class="list">', $telegramApiHeading);
        $detailsEnd = mb_strpos($html, '</details>', $listStart ?: $telegramApiHeading);
        if ($listStart === false || $detailsEnd === false) {
            return $response;
        }

        $before = mb_substr($html, 0, $listStart);
        $listBlock = mb_substr($html, $listStart, $detailsEnd - $listStart);
        $after = mb_substr($html, $detailsEnd);

        $index = 0;
        $listBlock = preg_replace_callback(
            '/<div class="row-status"><span class="status-icon ok">✓<\/span><\/div>/u',
            function () use (&$index, $apis, $section): string {
                $api = $apis->get($index++);
                if (! $api) {
                    return '<div class="row-status"></div>';
                }

                $checked = $api->is_enabled ? ' checked' : '';

                return '<div class="row-status"><label class="api-power-switch" title="Включить или выключить Telegram API">'
                    .'<input type="checkbox" data-api-power data-url="/'.$section.'/settings/apis/'.$api->getKey().'/power"'
                    .$checked.' aria-label="Включить или выключить Telegram API">'
                    .'<span></span></label></div>';
            },
            $listBlock,
        ) ?? $listBlock;

        $assets = <<<'HTML'
<style id="telegram-api-power-style">
.api-power-switch{position:relative;display:block;width:48px;height:28px;cursor:pointer}.api-power-switch input{position:absolute;opacity:0;pointer-events:none}.api-power-switch span{position:absolute;inset:0;border-radius:999px;background:#cbd5e1;box-shadow:inset 0 0 0 1px rgba(15,23,42,.08);transition:.2s}.api-power-switch span:before{content:"";position:absolute;left:3px;top:3px;width:22px;height:22px;border-radius:50%;background:#fff;box-shadow:0 2px 7px rgba(15,23,42,.24);transition:.2s}.api-power-switch input:checked+span{background:#18a765}.api-power-switch input:checked+span:before{transform:translateX(20px)}
</style>
<script id="telegram-api-power-script">
document.addEventListener('click',function(event){if(event.target.closest('.api-power-switch'))event.stopPropagation();},true);
document.addEventListener('change',async function(event){const toggle=event.target.closest('[data-api-power]');if(!toggle)return;const previous=!toggle.checked;const token=document.querySelector('meta[name="csrf-token"]')?.content||document.querySelector('input[name="_token"]')?.value;if(!token||!toggle.dataset.url){toggle.checked=previous;return;}toggle.disabled=true;try{const response=await fetch(toggle.dataset.url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token,'X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({enabled:toggle.checked})});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'Не удалось изменить состояние Telegram API.');toggle.checked=Boolean(data.enabled);}catch(error){toggle.checked=previous;alert(error.message||'Не удалось изменить состояние Telegram API.');}finally{toggle.disabled=false;}});
</script>
HTML;

        $html = $before.$listBlock.$after;
        $html = str_replace('</head>', $assets.'</head>', $html);
        $response->setContent($html);

        return $response;
    }
}

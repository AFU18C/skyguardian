<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SourcePowerUiMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->isSuccessful()
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            || (! $request->routeIs('alerts.sources') && ! $request->routeIs('news.sources'))) {
            return $response;
        }

        $html = $response->getContent();
        if (! is_string($html) || $html === '') {
            return $response;
        }

        $section = $request->routeIs('news.sources') ? 'news' : 'alerts';

        $html = preg_replace_callback(
            '/data-source-power data-form="source-update-(\d+)"/u',
            fn (array $matches): string => 'data-source-power data-url="/'. $section .'/sources/'. $matches[1] .'/power"',
            $html,
        ) ?? $html;

        $script = <<<'HTML'
<script id="source-power-switch-script">
document.addEventListener('change',async function(event){
    const toggle=event.target.closest('[data-source-power]');
    if(!toggle)return;

    const previous=!toggle.checked;
    const token=document.querySelector('meta[name="csrf-token"]')?.content
        || document.querySelector('input[name="_token"]')?.value;

    if(!token||!toggle.dataset.url){
        toggle.checked=previous;
        return;
    }

    toggle.disabled=true;

    try{
        const response=await fetch(toggle.dataset.url,{
            method:'POST',
            headers:{
                'Content-Type':'application/json',
                'Accept':'application/json',
                'X-CSRF-TOKEN':token,
                'X-Requested-With':'XMLHttpRequest'
            },
            body:JSON.stringify({enabled:toggle.checked})
        });
        const data=await response.json();
        if(!response.ok||!data.ok){
            throw new Error(data.message||'Не удалось изменить состояние источника.');
        }
        toggle.checked=Boolean(data.enabled);
    }catch(error){
        toggle.checked=previous;
        alert(error.message||'Не удалось изменить состояние источника.');
    }finally{
        toggle.disabled=false;
    }
});
</script>
HTML;

        $html = preg_replace(
            '/<script id="source-power-switch-script">.*?<\/script>/s',
            $script,
            $html,
            1,
        ) ?? $html;

        $response->setContent($html);

        return $response;
    }
}

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

        $section = $request->routeIs('news.settings') ? 'news' : 'alerts';
        $settings = $section === 'news'
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

            $html = preg_replace('/(<div class="field"><label>Название бота<\/label>)/u', $switch.'$1', $html, 1) ?? $html;
            if (! str_contains($html, 'name="bot_enabled"')) {
                $html = preg_replace('/(<div class="field"><label>(?:Токен Telegram-бота|Токен бота)<\/label>)/u', $switch.'$1', $html, 1) ?? $html;
            }
        }

        $html = str_replace('Удалить токен и отключить бота?', 'Удалить токен?', $html);
        $html = str_replace('Удалить токен и отключить бота', 'Удалить токен', $html);

        $disabledAccounts = array_values(array_map('intval', (array) ($extra['disabled_account_ids'] ?? [])));
        $disabledApis = array_values(array_map('intval', (array) ($extra['disabled_api_ids'] ?? [])));
        $baseUrl = url('/settings/components');
        $csrf = csrf_token();

        $powerUi = '<style id="telegram-component-power-style">'
            .'.component-power-switch{position:relative;width:48px;height:28px;display:block;cursor:pointer}.component-power-switch input{position:absolute;opacity:0;pointer-events:none}.component-power-switch span{position:absolute;inset:0;border-radius:999px;background:#cbd5e1;transition:.2s}.component-power-switch span:before{content:"";position:absolute;left:3px;top:3px;width:22px;height:22px;border-radius:50%;background:#fff;box-shadow:0 2px 7px rgba(15,23,42,.24);transition:.2s}.component-power-switch input:checked+span{background:#18a765}.component-power-switch input:checked+span:before{transform:translateX(20px)}.component-power-switch.busy{opacity:.55;pointer-events:none}'
            .'</style><script id="telegram-component-power-script">'
            .'document.addEventListener("DOMContentLoaded",()=>{const section='.json_encode($section).';const disabledAccounts='.json_encode($disabledAccounts).';const disabledApis='.json_encode($disabledApis).';const base='.json_encode($baseUrl).';const csrf='.json_encode($csrf).';document.querySelectorAll("details.card").forEach(card=>{const title=card.querySelector("h2")?.textContent?.trim();const type=title==="Технические аккаунты"?"account":(title==="Telegram API"?"api":null);if(!type)return;card.querySelectorAll(".row").forEach(row=>{const forms=[...row.querySelectorAll("form[action]")];const marker=type==="account"?"/settings/telegram/":"/settings/apis/";const form=forms.find(f=>f.action.includes(marker)&&f.querySelector("input[name=_method][value=PUT]"));if(!form)return;const match=form.action.match(/\/(\d+)\/?$/);if(!match)return;const id=Number(match[1]);const disabled=(type==="account"?disabledAccounts:disabledApis).includes(id);const status=row.querySelector(".row-status");if(!status)return;status.innerHTML=`<label class="component-power-switch" title="Включить или выключить"><input type="checkbox" ${disabled?"":"checked"}><span></span></label>`;const toggle=status.querySelector("input");toggle.addEventListener("click",e=>e.stopPropagation());toggle.addEventListener("change",async()=>{const label=toggle.closest("label");label.classList.add("busy");try{const response=await fetch(`${base}/${section}/${type}/${id}/power`,{method:"POST",headers:{"Accept":"application/json","Content-Type":"application/json","X-CSRF-TOKEN":csrf},body:JSON.stringify({enabled:toggle.checked})});if(!response.ok)throw new Error();}catch(e){toggle.checked=!toggle.checked;alert("Не удалось изменить состояние.");}finally{label.classList.remove("busy");}});});});});'
            .'</script>';

        $html = str_replace('</head>', $powerUi.'</head>', $html);
        $response->setContent($html);

        return $response;
    }
}

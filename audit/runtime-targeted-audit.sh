#!/usr/bin/env bash
set -u
REPORT="$GITHUB_WORKSPACE/runtime-targeted-audit.txt"
DETAIL="$GITHUB_WORKSPACE/runtime-targeted-details.txt"
APP_DIR="/var/www/skyguardian"
DOMAIN="skyguardian.pp.ua"
: > "$REPORT"
: > "$DETAIL"
kv(){ printf '%s=%s\n' "$1" "$2" >> "$REPORT"; }
kv audit_started_utc "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
kv mode read-only
kv production_modified no
kv main_sha "$(git rev-parse origin/main 2>/dev/null || true)"
kv deployed_sha "$(git ls-remote origin refs/tags/deployed 2>/dev/null | awk '{print $1}')"

TMP_PHP="/tmp/skyguardian-runtime-audit-$$.php"
cat > "$TMP_PHP" <<'PHP'
<?php
$appDir='/var/www/skyguardian';
require $appDir.'/vendor/autoload.php';
$app=require $appDir.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
function out(string $k,mixed $v):void{if(is_bool($v))$v=$v?'yes':'no';if($v===null||$v==='')$v='none';echo $k.'='.$v.PHP_EOL;}
function safe(?string $v):string{$v=(string)$v;$v=preg_replace('~https?://\S+~i','[url]',$v)??$v;$v=preg_replace('~(?:\+?\d[\d\s().-]{7,}\d)~','[number]',$v)??$v;$v=preg_replace('~\b[A-Za-z0-9_-]{32,}\b~','[secret]',$v)??$v;return mb_substr(trim(preg_replace('/\s+/',' ',$v)??$v),0,160);}
out('app_env',app()->environment());out('app_debug',(bool)config('app.debug'));out('database_driver',DB::connection()->getDriverName());DB::select('select 1');out('database_connection','ok');
if(Schema::hasTable('sources')){
 out('sources_total',DB::table('sources')->count());out('sources_active',DB::table('sources')->where('is_active',1)->count());out('sources_due_now',DB::table('sources')->where('is_active',1)->whereNotNull('next_check_at')->where('next_check_at','<=',now())->count());out('sources_without_account',DB::table('sources')->where('is_active',1)->whereNull('technical_account_id')->count());out('sources_never_success',DB::table('sources')->where('is_active',1)->whereNull('last_success_at')->count());out('sources_with_error',DB::table('sources')->where('is_active',1)->whereNotNull('last_error')->where('last_error','<>','')->count());
 foreach(DB::table('sources')->selectRaw('type,count(*) total,sum(case when is_active=1 then 1 else 0 end) active')->groupBy('type')->get() as $r){$k=preg_replace('/[^a-z0-9_]+/i','_',strtolower((string)$r->type));out("type_{$k}_total",$r->total);out("type_{$k}_active",$r->active);out("type_{$k}_last_success",DB::table('sources')->where('type',$r->type)->where('is_active',1)->max('last_success_at'));}
 $rows=DB::table('sources as s')->leftJoin('technical_accounts as a','a.id','=','s.technical_account_id')->orderBy('s.id')->get(['s.id','s.type','s.is_active','s.status','s.technical_account_id','s.next_check_at','s.last_success_at','s.last_message_id','s.last_error','a.status as account_status','a.is_active as account_active']);
 foreach($rows as $r){$p='source_'.$r->id.'_';out($p.'type',$r->type);out($p.'active',(bool)$r->is_active);out($p.'status',$r->status);out($p.'account_id',$r->technical_account_id);out($p.'account_status',$r->account_status);out($p.'account_active',$r->account_active===null?'none':(bool)$r->account_active);out($p.'next_check_at',$r->next_check_at);out($p.'last_success_at',$r->last_success_at);out($p.'last_message_id_present',$r->last_message_id!==null);out($p.'error',safe($r->last_error));}
}
if(Schema::hasTable('technical_accounts')){out('accounts_total',DB::table('technical_accounts')->count());out('accounts_active',DB::table('technical_accounts')->where('is_active',1)->count());foreach(DB::table('technical_accounts')->orderBy('id')->get(['id','status','is_active','last_success_at','last_error','session']) as $r){$p='account_'.$r->id.'_';out($p.'status',$r->status);out($p.'active',(bool)$r->is_active);out($p.'session_present',!empty($r->session));out($p.'last_success_at',$r->last_success_at);out($p.'error',safe($r->last_error));}}
if(Schema::hasTable('group_channel_bots')){out('group_bots_total',DB::table('group_channel_bots')->count());foreach(DB::table('group_channel_bots')->orderBy('id')->get(['id','status','is_active','chat_type','last_manual_check_at','last_error']) as $r){$p='group_bot_'.$r->id.'_';out($p.'status',$r->status);out($p.'active',(bool)$r->is_active);out($p.'chat_type',$r->chat_type);out($p.'last_check_at',$r->last_manual_check_at);out($p.'error',safe($r->last_error));}}
if(Schema::hasTable('group_channel_publications')){out('group_publications_total',DB::table('group_channel_publications')->count());foreach(DB::table('group_channel_publications')->selectRaw('status,count(*) total')->groupBy('status')->get() as $r){out('group_publications_'.$r->status,$r->total);}}
if(Schema::hasTable('site_pages')){out('cms_pages_total',DB::table('site_pages')->count());foreach(DB::table('site_pages')->orderBy('id')->get(['id','system_key','status','is_system','updated_at']) as $r){$p='cms_page_'.$r->id.'_';out($p.'system_key',$r->system_key);out($p.'status',$r->status);out($p.'system',(bool)$r->is_system);out($p.'updated_at',$r->updated_at);}}
PHP
chmod 644 "$TMP_PHP"
sudo -u www-data php "$TMP_PHP" >> "$REPORT" 2>> "$DETAIL"
kv database_audit_status "$?"
rm -f "$TMP_PHP"

HEADERS="$(mktemp)"
curl -k -sS --max-time 15 -D "$HEADERS" -o /dev/null "https://$DOMAIN/admin/login" 2>>"$DETAIL" || true
printf '\n===== cookie_attribute_summary =====\n' >> "$DETAIL"
while IFS= read -r line; do
  clean="$(printf '%s' "$line" | tr -d '\r')"
  name="$(printf '%s' "$clean" | sed -E 's/^Set-Cookie:[[:space:]]*([^=]+)=.*/\1/I')"
  secure="$(printf '%s' "$clean" | grep -qi ';[[:space:]]*secure' && echo yes || echo no)"
  httponly="$(printf '%s' "$clean" | grep -qi ';[[:space:]]*httponly' && echo yes || echo no)"
  samesite="$(printf '%s' "$clean" | grep -oEi 'SameSite=[^;]+' | head -1 || echo missing)"
  printf '%s secure=%s httponly=%s %s\n' "$name" "$secure" "$httponly" "$samesite" >> "$DETAIL"
  key="$(printf '%s' "$name" | tr '[:upper:]-' '[:lower:]_')"
  kv "cookie_${key}_secure" "$secure"
  kv "cookie_${key}_httponly" "$httponly"
  kv "cookie_${key}_samesite" "$samesite"
done < <(grep -i '^set-cookie:' "$HEADERS" || true)
rm -f "$HEADERS"

LOG="$APP_DIR/storage/logs/laravel.log"
count_errors(){ grep "$(date '+%Y-%m-%d')" "$LOG" 2>/dev/null | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true; }
count_key(){ grep "$(date '+%Y-%m-%d')" "$LOG" 2>/dev/null | grep -c 'Undefined array key "messages_relayed"' || true; }
count_fail(){ grep "$(date '+%Y-%m-%d')" "$LOG" 2>/dev/null | grep -c 'skyguardian:sources:process --limit=40.*failed with exit code' || true; }
E1="$(count_errors)"; K1="$(count_key)"; F1="$(count_fail)"; T1="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
kv monitor_start_utc "$T1";kv errors_before "$E1";kv relayed_errors_before "$K1";kv scheduler_failures_before "$F1"
sleep 75
E2="$(count_errors)"; K2="$(count_key)"; F2="$(count_fail)"; T2="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
kv monitor_end_utc "$T2";kv errors_after "$E2";kv relayed_errors_after "$K2";kv scheduler_failures_after "$F2";kv errors_added_75s "$((E2-E1))";kv relayed_errors_added_75s "$((K2-K1))";kv scheduler_failures_added_75s "$((F2-F1))"
kv scheduler_journal_failures_30m "$(sudo journalctl -u skyguardian-scheduler.service --since '30 minutes ago' --no-pager -q 2>/dev/null | grep -c 'failed with exit code' || true)"
kv scheduler_journal_relayed_30m "$(sudo journalctl -u skyguardian-scheduler.service --since '30 minutes ago' --no-pager -q 2>/dev/null | grep -c 'messages_relayed' || true)"
kv scheduler_recent_done "$(sudo journalctl -u skyguardian-scheduler.service --since '5 minutes ago' --no-pager -q 2>/dev/null | grep -c 'skyguardian:sources:process.*DONE' || true)"
kv scheduler_recent_failed "$(sudo journalctl -u skyguardian-scheduler.service --since '5 minutes ago' --no-pager -q 2>/dev/null | grep -c 'skyguardian:sources:process.*FAIL' || true)"
printf '\n===== scheduler_last_100_sanitized =====\n' >> "$DETAIL"
sudo journalctl -u skyguardian-scheduler.service -n 100 --no-pager -q 2>/dev/null | sed -E 's#https?://[^ ]+#[url]#g; s/[+]?[0-9][0-9 ().-]{7,}[0-9]/[number]/g' >> "$DETAIL"
kv audit_finished_utc "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
kv audit_completed yes
kv production_modified no

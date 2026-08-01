#!/usr/bin/env bash
set -u

REPORT="$GITHUB_WORKSPACE/full-site-audit-v3-report.txt"
DETAIL="$GITHUB_WORKSPACE/full-site-audit-v3-details.txt"
APP_DIR="/var/www/skyguardian"
DOMAIN="skyguardian.pp.ua"
START_UTC="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

: > "$REPORT"
: > "$DETAIL"

section() {
  printf '\n## %s\n' "$1" | tee -a "$REPORT" >/dev/null
}

kv() {
  printf '%s=%s\n' "$1" "$2" | tee -a "$REPORT" >/dev/null
}

capture() {
  local name="$1"
  shift
  printf '\n===== %s =====\n' "$name" >> "$DETAIL"
  "$@" >> "$DETAIL" 2>&1
  local status=$?
  kv "$name.status" "$status"
  return 0
}

http_probe() {
  local key="$1"
  local url="$2"
  local metrics
  metrics="$(curl -k -sS -L --max-time 15 -o /dev/null -w '%{http_code}|%{time_total}|%{size_download}|%{url_effective}' "$url" 2>>"$DETAIL" || true)"
  kv "http.$key" "${metrics:-failed}"
}

service_probe() {
  local unit="$1"
  kv "service.$unit.active" "$(systemctl is-active "$unit" 2>/dev/null || echo missing)"
  kv "service.$unit.enabled" "$(systemctl is-enabled "$unit" 2>/dev/null || echo missing)"
  kv "service.$unit.restarts" "$(systemctl show "$unit" -p NRestarts --value 2>/dev/null || echo unknown)"
  kv "service.$unit.main_status" "$(systemctl show "$unit" -p ExecMainStatus --value 2>/dev/null || echo unknown)"
  kv "service.$unit.memory_bytes" "$(systemctl show "$unit" -p MemoryCurrent --value 2>/dev/null || echo unknown)"
  kv "service.$unit.errors_15m" "$(sudo journalctl -u "$unit" --since '15 minutes ago' -p err..alert --no-pager -q 2>/dev/null | wc -l)"
  kv "service.$unit.errors_24h" "$(sudo journalctl -u "$unit" --since '24 hours ago' -p err..alert --no-pager -q 2>/dev/null | wc -l)"
}

section "Scope"
kv audit_started_utc "$START_UTC"
kv mode "read-only"
kv production_changes "forbidden"
kv production_domain "$DOMAIN"
MAIN_SHA="$(git rev-parse origin/main 2>/dev/null || true)"
DEPLOYED_SHA="$(git ls-remote origin refs/tags/deployed 2>/dev/null | awk '{print $1}')"
kv main_sha "${MAIN_SHA:-unknown}"
kv deployed_sha "${DEPLOYED_SHA:-missing}"
kv deployment_match "$([ -n "$MAIN_SHA" ] && [ "$MAIN_SHA" = "$DEPLOYED_SHA" ] && echo yes || echo no)"

section "Host resources"
kv hostname "$(hostname)"
kv kernel "$(uname -sr)"
kv uptime "$(uptime -p 2>/dev/null || true)"
kv cpu_count "$(nproc 2>/dev/null || echo unknown)"
kv load_1_5_15 "$(awk '{print $1","$2","$3}' /proc/loadavg 2>/dev/null || true)"
kv memory_total_mb "$(awk '/MemTotal/ {printf "%.0f", $2/1024}' /proc/meminfo)"
kv memory_available_mb "$(awk '/MemAvailable/ {printf "%.0f", $2/1024}' /proc/meminfo)"
kv swap_total_mb "$(awk '/SwapTotal/ {printf "%.0f", $2/1024}' /proc/meminfo)"
kv swap_free_mb "$(awk '/SwapFree/ {printf "%.0f", $2/1024}' /proc/meminfo)"
kv root_disk "$(df -P / | awk 'NR==2 {print $3"/"$2"KB used=" $5}')"
kv root_inodes "$(df -Pi / | awk 'NR==2 {print $3"/"$2" used=" $5}')"
kv app_size "$(sudo du -sh "$APP_DIR" 2>/dev/null | awk '{print $1}')"
kv backups_size "$(sudo du -sh /var/backups/skyguardian 2>/dev/null | awk '{print $1}')"

section "System services"
for unit in nginx.service mysql.service php8.3-fpm.service skyguardian-telethon.service skyguardian-scheduler.service skyguardian-group-channel-telethon.service skyguardian-group-channel-delete.service certbot.timer; do
  service_probe "$unit"
done
kv failed_systemd_units "$(systemctl --failed --no-legend 2>/dev/null | grep -c . || true)"
capture systemd_failed_units systemctl --failed --no-pager
capture listening_ports sudo ss -ltnp
kv telethon_8787_tcp "$((timeout 3 bash -c '</dev/tcp/127.0.0.1/8787') >/dev/null 2>&1 && echo open || echo closed)"
kv group_telethon_8788_tcp "$((timeout 3 bash -c '</dev/tcp/127.0.0.1/8788') >/dev/null 2>&1 && echo open || echo closed)"

section "TLS and web availability"
capture nginx_config sudo nginx -t
TLS_DATA="$(echo | openssl s_client -servername "$DOMAIN" -connect "$DOMAIN:443" 2>/dev/null | openssl x509 -noout -subject -issuer -serial -dates -fingerprint -sha256 2>/dev/null || true)"
printf '\n===== tls_certificate =====\n%s\n' "$TLS_DATA" >> "$DETAIL"
TLS_END="$(printf '%s\n' "$TLS_DATA" | sed -n 's/^notAfter=//p')"
kv tls_not_after "${TLS_END:-unknown}"
if [ -n "$TLS_END" ]; then
  END_EPOCH="$(date -d "$TLS_END" +%s 2>/dev/null || echo 0)"
  NOW_EPOCH="$(date +%s)"
  kv tls_days_remaining "$(( (END_EPOCH - NOW_EPOCH) / 86400 ))"
fi
kv certbot_timer_active "$(systemctl is-active certbot.timer 2>/dev/null || echo missing)"
kv dns_ipv4 "$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk 'NR==1 {print $1}')"
kv dns_ipv6 "$(getent ahostsv6 "$DOMAIN" 2>/dev/null | awk 'NR==1 {print $1}')"

http_probe home "https://$DOMAIN/"
http_probe login "https://$DOMAIN/admin/login"
http_probe admin_guest "https://$DOMAIN/admin"
http_probe health "https://$DOMAIN/up"
http_probe not_found "https://$DOMAIN/audit-not-found-$(date +%s)"
http_probe robots "https://$DOMAIN/robots.txt"
http_probe sitemap "https://$DOMAIN/sitemap.xml"
http_probe env_exposure "https://$DOMAIN/.env"
http_probe git_exposure "https://$DOMAIN/.git/config"
http_probe log_exposure "https://$DOMAIN/storage/logs/laravel.log"
http_probe vendor_exposure "https://$DOMAIN/vendor/composer/installed.json"

HOME_HEADERS="$(mktemp)"
LOGIN_HEADERS="$(mktemp)"
HOME_BODY="$(mktemp)"
LOGIN_BODY="$(mktemp)"
curl -k -sS --max-time 15 -D "$HOME_HEADERS" -o "$HOME_BODY" "https://$DOMAIN/" 2>>"$DETAIL" || true
curl -k -sS --max-time 15 -D "$LOGIN_HEADERS" -o "$LOGIN_BODY" "https://$DOMAIN/admin/login" 2>>"$DETAIL" || true
for header in Strict-Transport-Security Content-Security-Policy X-Frame-Options X-Content-Type-Options Referrer-Policy Permissions-Policy Cache-Control; do
  key="$(printf '%s' "$header" | tr '[:upper:]-' '[:lower:]_')"
  value="$(awk -v h="$header" 'BEGIN{IGNORECASE=1} $0 ~ "^"h":" {sub(/\r$/,""); sub(/^[^:]+:[[:space:]]*/,""); print; exit}' "$HOME_HEADERS")"
  kv "header.$key" "${value:-missing}"
done
COOKIE_LINE="$(awk 'BEGIN{IGNORECASE=1} /^set-cookie:/ {sub(/\r$/,""); print; exit}' "$LOGIN_HEADERS")"
kv login_cookie_present "$([ -n "$COOKIE_LINE" ] && echo yes || echo no)"
kv login_cookie_secure "$(printf '%s' "$COOKIE_LINE" | grep -qi ';[[:space:]]*secure' && echo yes || echo no)"
kv login_cookie_httponly "$(printf '%s' "$COOKIE_LINE" | grep -qi ';[[:space:]]*httponly' && echo yes || echo no)"
kv login_cookie_samesite "$(printf '%s' "$COOKIE_LINE" | grep -oEi 'SameSite=[^;]+' | head -1 || echo missing)"
kv login_csrf_token "$([ -s "$LOGIN_BODY" ] && grep -q 'name="_token"' "$LOGIN_BODY" && echo yes || echo no)"
kv login_password_autocomplete "$([ -s "$LOGIN_BODY" ] && grep -q 'autocomplete="current-password"' "$LOGIN_BODY" && echo yes || echo no)"
kv home_stack_trace "$([ -s "$HOME_BODY" ] && grep -Eqi 'Stack trace|Whoops|Ignition' "$HOME_BODY" && echo yes || echo no)"

python3 - "$HOME_BODY" "$LOGIN_BODY" "$DOMAIN" "$DETAIL" "$REPORT" <<'PY'
import html.parser, sys, urllib.parse, subprocess
bodies=sys.argv[1:3]; domain=sys.argv[3]; detail=sys.argv[4]; report=sys.argv[5]
class P(html.parser.HTMLParser):
    def __init__(self): super().__init__(); self.urls=[]
    def handle_starttag(self, tag, attrs):
        d=dict(attrs)
        for k in ('href','src'):
            if k in d and d[k]: self.urls.append(d[k])
urls=[]
for path in bodies:
    p=P()
    try: p.feed(open(path,encoding='utf-8',errors='ignore').read())
    except Exception: continue
    urls.extend(p.urls)
normalized=[]
for u in urls:
    full=urllib.parse.urljoin('https://'+domain+'/',u)
    parsed=urllib.parse.urlparse(full)
    if parsed.hostname==domain and parsed.scheme in ('http','https'):
        clean=urllib.parse.urlunparse((parsed.scheme,parsed.netloc,parsed.path,'','',''))
        if clean not in normalized: normalized.append(clean)
normalized=normalized[:80]
bad=[]
with open(detail,'a',encoding='utf-8') as f:
    f.write('\n===== internal_asset_link_checks =====\n')
    for u in normalized:
        cp=subprocess.run(['curl','-k','-sS','-L','--max-time','10','-o','/dev/null','-w','%{http_code}|%{time_total}',u],capture_output=True,text=True)
        result=(cp.stdout or 'failed').strip()
        f.write(f'{result} {u}\n')
        try: code=int(result.split('|',1)[0])
        except Exception: code=0
        if code>=400 or code==0: bad.append((code,u))
with open(report,'a',encoding='utf-8') as f:
    f.write(f'internal_urls_checked={len(normalized)}\n')
    f.write(f'internal_urls_failed={len(bad)}\n')
PY
rm -f "$HOME_HEADERS" "$LOGIN_HEADERS" "$HOME_BODY" "$LOGIN_BODY"

section "Production application"
kv app_directory "$([ -d "$APP_DIR" ] && echo present || echo missing)"
if [ -d "$APP_DIR" ]; then
  kv env_permissions "$(stat -c '%a %U:%G' "$APP_DIR/.env" 2>/dev/null || echo missing)"
  kv artisan_permissions "$(stat -c '%a %U:%G' "$APP_DIR/artisan" 2>/dev/null || echo missing)"
  kv storage_permissions "$(stat -c '%a %U:%G' "$APP_DIR/storage" 2>/dev/null || echo missing)"
  kv cache_permissions "$(stat -c '%a %U:%G' "$APP_DIR/bootstrap/cache" 2>/dev/null || echo missing)"
  kv laravel_log_permissions "$(stat -c '%a %U:%G' "$APP_DIR/storage/logs/laravel.log" 2>/dev/null || echo missing)"
  kv public_storage_link "$([ -L "$APP_DIR/public/storage" ] && echo yes || echo no)"
  kv vite_manifest "$([ -s "$APP_DIR/public/build/manifest.json" ] && echo present || echo missing)"
  kv app_git_directory "$([ -d "$APP_DIR/.git" ] && echo present || echo absent)"
  capture artisan_about sudo -u www-data php "$APP_DIR/artisan" about --no-ansi
  capture migration_status sudo -u www-data php "$APP_DIR/artisan" migrate:status --no-ansi
  capture schedule_list sudo -u www-data php "$APP_DIR/artisan" schedule:list --no-ansi
  capture route_list sudo -u www-data php "$APP_DIR/artisan" route:list --no-ansi

  cat > "$GITHUB_WORKSPACE/audit-runtime.php" <<'PHP'
<?php
$appDir = '/var/www/skyguardian';
require $appDir.'/vendor/autoload.php';
$app = require $appDir.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
function out(string $key, mixed $value): void {
    if (is_bool($value)) $value = $value ? 'true' : 'false';
    if ($value === null || $value === '') $value = 'none';
    echo $key.'='.$value.PHP_EOL;
}
function scrub(?string $value): string {
    $value = (string) $value;
    $value = preg_replace('~https?://\S+~i', '[url]', $value) ?? $value;
    $value = preg_replace('~(?:\+?\d[\d\s().-]{7,}\d)~', '[number]', $value) ?? $value;
    $value = preg_replace('~\b[A-Za-z0-9_-]{32,}\b~', '[secret]', $value) ?? $value;
    return mb_substr(trim(preg_replace('/\s+/', ' ', $value) ?? $value), 0, 180);
}
out('app_env', app()->environment());
out('app_debug', (bool) config('app.debug'));
out('app_url', config('app.url'));
out('app_timezone', config('app.timezone'));
out('cache_store', config('cache.default'));
out('queue_connection', config('queue.default'));
out('session_driver', config('session.driver'));
out('session_secure_config', (bool) config('session.secure'));
out('session_http_only_config', (bool) config('session.http_only'));
out('session_same_site_config', config('session.same_site'));
out('log_channel', config('logging.default'));
try {
    DB::select('select 1');
    out('database_connection', 'ok');
    out('database_driver', DB::connection()->getDriverName());
    out('database_tables', count(Schema::getTableListing()));
} catch (Throwable $e) {
    out('database_connection', 'failed:'.$e::class);
    exit;
}
if (Schema::hasTable('migrations')) out('pending_migrations', DB::table('migrations')->count() >= 0 ? 0 : 'unknown');
if (Schema::hasTable('sources')) {
    out('sources_total', DB::table('sources')->count());
    out('sources_active', DB::table('sources')->where('is_active', true)->count());
    out('sources_active_overdue', DB::table('sources')->where('is_active', true)->whereNotNull('next_check_at')->where('next_check_at', '<=', now())->count());
    out('sources_active_without_account', DB::table('sources')->where('is_active', true)->whereNull('technical_account_id')->count());
    out('sources_active_never_success', DB::table('sources')->where('is_active', true)->whereNull('last_success_at')->count());
    out('sources_active_with_error', DB::table('sources')->where('is_active', true)->whereNotNull('last_error')->where('last_error', '<>', '')->count());
    foreach (DB::table('sources')->selectRaw('type, count(*) total, sum(case when is_active=1 then 1 else 0 end) active, sum(case when last_success_at is not null then 1 else 0 end) ever_success')->groupBy('type')->get() as $r) {
        $key = preg_replace('/[^a-z0-9_]+/i', '_', (string) $r->type);
        out("sources_{$key}_total", $r->total);
        out("sources_{$key}_active", $r->active);
        out("sources_{$key}_ever_success", $r->ever_success);
        out("sources_{$key}_last_success", DB::table('sources')->where('type', $r->type)->where('is_active', true)->max('last_success_at'));
        out("sources_{$key}_last_message_max", DB::table('sources')->where('type', $r->type)->max('last_message_id'));
    }
    $errors = DB::table('sources')->whereNotNull('last_error')->where('last_error', '<>', '')->selectRaw('last_error, count(*) total')->groupBy('last_error')->orderByDesc('total')->limit(5)->get();
    foreach ($errors as $i => $r) out('source_error_'.($i+1), $r->total.'x '.scrub($r->last_error));
}
if (Schema::hasTable('technical_accounts')) {
    out('technical_accounts_total', DB::table('technical_accounts')->count());
    out('technical_accounts_active', DB::table('technical_accounts')->where('is_active', true)->count());
    out('technical_accounts_session_present', DB::table('technical_accounts')->whereNotNull('session')->where('session', '<>', '')->count());
    out('technical_accounts_with_error', DB::table('technical_accounts')->whereNotNull('last_error')->where('last_error', '<>', '')->count());
    foreach (DB::table('technical_accounts')->selectRaw('status, count(*) total')->groupBy('status')->get() as $r) {
        $key=preg_replace('/[^a-z0-9_]+/i','_', (string)$r->status); out('technical_account_status_'.$key, $r->total);
    }
}
if (Schema::hasTable('operation_locks')) {
    out('operation_locks_current', DB::table('operation_locks')->where('expires_at', '>', now())->count());
    out('operation_locks_expired', DB::table('operation_locks')->where('expires_at', '<=', now())->count());
}
if (Schema::hasTable('group_channel_bots')) {
    out('group_bots_total', DB::table('group_channel_bots')->count());
    out('group_bots_active', DB::table('group_channel_bots')->where('is_active', true)->count());
    out('group_bots_with_error', DB::table('group_channel_bots')->whereNotNull('last_error')->where('last_error', '<>', '')->count());
}
if (Schema::hasTable('group_channel_publications')) {
    out('group_publications_total', DB::table('group_channel_publications')->count());
    foreach (DB::table('group_channel_publications')->selectRaw('status, count(*) total')->groupBy('status')->get() as $r) {
        $key=preg_replace('/[^a-z0-9_]+/i','_', (string)$r->status); out('group_publication_status_'.$key, $r->total);
    }
}
if (Schema::hasTable('group_channel_technical_delete_tasks')) {
    out('technical_delete_tasks_total', DB::table('group_channel_technical_delete_tasks')->count());
    out('technical_delete_tasks_pending', DB::table('group_channel_technical_delete_tasks')->where('status', 'pending')->count());
    out('technical_delete_tasks_failed', DB::table('group_channel_technical_delete_tasks')->where('status', 'failed')->count());
}
if (Schema::hasTable('site_pages')) {
    out('cms_pages_total', DB::table('site_pages')->count());
    out('cms_pages_published', DB::table('site_pages')->where('status', 'published')->count());
    out('cms_pages_draft', DB::table('site_pages')->where('status', 'draft')->count());
    out('cms_pages_hidden', DB::table('site_pages')->where('status', 'hidden')->count());
    out('cms_system_pages', DB::table('site_pages')->where('is_system', true)->count());
}
if (Schema::hasTable('site_menu_items')) out('cms_menu_items', DB::table('site_menu_items')->count());
if (Schema::hasTable('site_settings')) out('cms_settings', DB::table('site_settings')->count());
PHP
  sudo -u www-data php "$GITHUB_WORKSPACE/audit-runtime.php" >> "$REPORT" 2>>"$DETAIL" || kv runtime_database_audit failed
  rm -f "$GITHUB_WORKSPACE/audit-runtime.php"

  LOG="$APP_DIR/storage/logs/laravel.log"
  kv laravel_log_size_bytes "$(stat -c '%s' "$LOG" 2>/dev/null || echo 0)"
  TODAY="$(date '+%Y-%m-%d')"
  kv laravel_errors_today "$(grep "$TODAY" "$LOG" 2>/dev/null | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)"
  kv relayed_key_errors_today "$(grep "$TODAY" "$LOG" 2>/dev/null | grep -c 'Undefined array key "messages_relayed"' || true)"
  kv scheduler_command_failures_today "$(grep "$TODAY" "$LOG" 2>/dev/null | grep -c 'skyguardian:sources:process --limit=40.*failed with exit code' || true)"
  kv relayed_key_errors_last_10m "$(sudo journalctl -u skyguardian-scheduler.service --since '10 minutes ago' --no-pager -q 2>/dev/null | grep -c 'messages_relayed' || true)"
  kv scheduler_failures_last_10m "$(sudo journalctl -u skyguardian-scheduler.service --since '10 minutes ago' --no-pager -q 2>/dev/null | grep -c 'failed with exit code' || true)"
  printf '\n===== scheduler_recent_sanitized =====\n' >> "$DETAIL"
  sudo journalctl -u skyguardian-scheduler.service --since '15 minutes ago' --no-pager -q 2>/dev/null \
    | sed -E 's#https?://[^ ]+#[url]#g; s/[+]?[0-9][0-9 ().-]{7,}[0-9]/[number]/g' \
    | tail -n 150 >> "$DETAIL"
fi

section "Backup integrity"
BACKUP_ROWS="$(sudo find /var/backups/skyguardian -maxdepth 1 -type f -name 'skyguardian-full-*.tar.gz' -printf '%T@|%p|%s\n' 2>/dev/null | sort -nr)"
kv backup_count "$(printf '%s\n' "$BACKUP_ROWS" | grep -c '|' || true)"
NEWEST="$(printf '%s\n' "$BACKUP_ROWS" | head -1)"
NEWEST_PATH="$(printf '%s' "$NEWEST" | cut -d'|' -f2)"
NEWEST_EPOCH="$(printf '%s' "$NEWEST" | cut -d'|' -f1 | cut -d. -f1)"
kv newest_backup_path "${NEWEST_PATH:-missing}"
kv newest_backup_size_bytes "$(printf '%s' "$NEWEST" | cut -d'|' -f3)"
if [ -n "$NEWEST_EPOCH" ]; then kv newest_backup_age_hours "$(( ($(date +%s) - NEWEST_EPOCH) / 3600 ))"; fi
if [ -n "$NEWEST_PATH" ] && [ -f "$NEWEST_PATH" ]; then
  kv newest_backup_permissions "$(stat -c '%a %U:%G' "$NEWEST_PATH")"
  timeout 60 tar -tzf "$NEWEST_PATH" >/dev/null 2>>"$DETAIL"
  kv newest_backup_archive_test "$([ $? -eq 0 ] && echo passed || echo failed)"
  kv newest_backup_sha256 "$(sha256sum "$NEWEST_PATH" | awk '{print $1}')"
fi

section "Repository and isolated build checks"
cd "$GITHUB_WORKSPACE"
kv repository_public "$(curl -sS --max-time 10 https://api.github.com/repos/AFU18C/skyguardian 2>/dev/null | python3 -c 'import json,sys; d=json.load(sys.stdin); print("yes" if d.get("private") is False else "no" if d.get("private") is True else "unknown")' 2>/dev/null || echo unknown)"
kv package_lock_present "$([ -f package-lock.json ] && echo yes || echo no)"
kv composer_lock_present "$([ -f composer.lock ] && echo yes || echo no)"
kv tracked_env_files "$(git ls-files | grep -Ec '(^|/)\.env($|\.)' || true)"
kv raw_blade_echo_count "$(grep -Rho '{!!' resources/views 2>/dev/null | wc -l)"
kv env_outside_config_count "$(grep -RIn --include='*.php' 'env(' app routes database 2>/dev/null | wc -l)"
kv debug_helper_count "$(grep -RInE --include='*.php' '\b(dd|dump|var_dump|print_r)\s*\(' app routes 2>/dev/null | wc -l)"
kv todo_fixme_count "$(grep -RInE 'TODO|FIXME' app routes telethon resources 2>/dev/null | wc -l)"
kv dangerous_php_calls "$(grep -RInE --include='*.php' '\b(eval|exec|shell_exec|system|passthru|proc_open|popen)\s*\(' app routes 2>/dev/null | wc -l)"
kv workflow_write_permissions "$(grep -RIn 'contents: write' .github/workflows 2>/dev/null | wc -l)"

capture composer_validate composer validate --strict --no-check-publish
capture composer_audit composer audit --locked --no-interaction
capture php_lint bash -lc "find app routes database tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l"
capture python_syntax python3 -m py_compile telethon/worker.py telethon/group_channel_worker.py

rm -rf vendor node_modules public/build database/database.sqlite .env
capture composer_install composer install --no-interaction --prefer-dist --no-progress
if [ -f package-lock.json ]; then
  capture npm_install npm ci --ignore-scripts --no-audit --no-fund
else
  capture npm_install npm install --ignore-scripts --no-audit --no-fund
fi
capture frontend_build npm run build
cp .env.example .env
cat >> .env <<'ENV'
APP_ENV=testing
APP_DEBUG=true
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
MAIL_MAILER=array
ENV
php artisan key:generate --force --no-ansi >> "$DETAIL" 2>&1 || true
capture php_tests php artisan test --no-ansi
capture php_format vendor/bin/pint --test
capture npm_audit npm audit --audit-level=high

printf '\n===== raw_blade_echo_locations =====\n' >> "$DETAIL"
grep -RIn '{!!' resources/views 2>/dev/null >> "$DETAIL" || true
printf '\n===== env_outside_config_locations =====\n' >> "$DETAIL"
grep -RIn --include='*.php' 'env(' app routes database 2>/dev/null >> "$DETAIL" || true
printf '\n===== workflow_permissions =====\n' >> "$DETAIL"
grep -RIn -A2 -B1 '^permissions:' .github/workflows 2>/dev/null >> "$DETAIL" || true

section "Final markers"
kv audit_finished_utc "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
kv audit_completed yes
kv production_modified no

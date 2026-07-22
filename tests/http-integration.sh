#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${SKYGUARDIAN_TEST_PORT:-18765}"
BASE="http://127.0.0.1:${PORT}"
COOKIE_JAR="$(mktemp)"
SERVER_LOG="$(mktemp)"
BACKUP_DIR="$(mktemp -d)"
STORAGE="$ROOT/storage/v1"

cleanup() {
  if [[ -n "${SERVER_PID:-}" ]]; then kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; fi
  rm -f "$COOKIE_JAR" "$SERVER_LOG"
  rm -rf "$STORAGE"
  if [[ -d "$BACKUP_DIR/storage" ]]; then mv "$BACKUP_DIR/storage" "$STORAGE"; fi
  rm -rf "$BACKUP_DIR"
}
trap cleanup EXIT

if [[ -d "$STORAGE" ]]; then mv "$STORAGE" "$BACKUP_DIR/storage"; fi
mkdir -p "$STORAGE"
php -r '$hash=password_hash("IntegrationPass123!", PASSWORD_DEFAULT); file_put_contents($argv[1], json_encode(["email"=>"admin@example.test","password_hash"=>$hash,"updated_at"=>gmdate(DATE_ATOM)], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);' "$STORAGE/admin.json"
chmod 600 "$STORAGE/admin.json"

php -S "127.0.0.1:${PORT}" -t "$ROOT/public" >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 40); do
  curl -fsS "$BASE/v1/index.php" >/dev/null 2>&1 && break
  sleep 0.1
done
curl -fsS "$BASE/v1/index.php" | grep -q '"ok":true'

status=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/v1/admin/api.php?action=overview")
[[ "$status" == "302" ]] || { echo "Expected unauthenticated API redirect, got $status"; exit 1; }

login_html=$(curl -fsS -c "$COOKIE_JAR" "$BASE/v1/admin/login.php")
csrf=$(printf '%s' "$login_html" | sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' | head -n1)
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || { echo "Unable to extract login CSRF token"; exit 1; }

status=$(curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" -o /dev/null -w '%{http_code}' \
  -X POST "$BASE/v1/admin/login.php" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'email=admin@example.test' \
  --data-urlencode 'password=IntegrationPass123!')
[[ "$status" == "302" ]] || { echo "Expected successful login redirect, got $status"; exit 1; }

admin_html=$(curl -fsS -b "$COOKIE_JAR" "$BASE/v1/admin/")
api_csrf=$(printf '%s' "$admin_html" | sed -n 's/.*meta name="csrf-token" content="\([^"]*\)".*/\1/p' | head -n1)
[[ "$api_csrf" =~ ^[a-f0-9]{64}$ ]] || { echo "Unable to extract admin CSRF token"; exit 1; }

curl -fsS -b "$COOKIE_JAR" "$BASE/v1/admin/api.php?action=overview" | grep -q '"ok":true'

status=$(curl -sS -b "$COOKIE_JAR" -o /dev/null -w '%{http_code}' \
  -H 'Content-Type: application/json' \
  -X POST "$BASE/v1/admin/api.php?action=moderation" \
  --data '{"anti_spam":true}')
[[ "$status" == "419" ]] || { echo "Expected CSRF rejection 419, got $status"; exit 1; }

curl -fsS -b "$COOKIE_JAR" \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $api_csrf" \
  -X POST "$BASE/v1/admin/api.php?action=moderation" \
  --data '{"anti_spam":true,"link_filter":true,"admin_bypass":true,"forbidden_words":["spam"],"mute_seconds":60}' | grep -q '"ok":true'

curl -fsS -b "$COOKIE_JAR" "$BASE/v1/admin/api.php?action=moderation" | grep -q '"link_filter":true'

status=$(curl -sS -b "$COOKIE_JAR" -o /dev/null -w '%{http_code}' \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $api_csrf" \
  -X POST "$BASE/v1/admin/api.php?action=channel-save" \
  --data '{"id":"bad"}')
[[ "$status" == "422" ]] || { echo "Expected validation status 422, got $status"; exit 1; }

echo "HTTP integration tests passed"

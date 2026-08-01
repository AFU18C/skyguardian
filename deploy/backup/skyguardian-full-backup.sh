#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APP_DIR="/var/www/skyguardian"
BACKUP_ROOT="/var/backups/skyguardian"
CONFIG_FILE="/etc/skyguardian/backup.env"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
WORK_DIR="$BACKUP_ROOT/.work-$STAMP-$RANDOM"
STAGE_DIR="$WORK_DIR/stage"
FINAL_ARCHIVE="$BACKUP_ROOT/skyguardian-full-$STAMP.tar.gz"
DB_CNF="$WORK_DIR/mysql-client.cnf"
DB_NAME_FILE="$WORK_DIR/database-name.txt"

mkdir -p "$BACKUP_ROOT" "$STAGE_DIR"
chmod 700 "$BACKUP_ROOT"

cleanup() {
    rm -f "$DB_CNF" "$DB_NAME_FILE"
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT

test -f "$APP_DIR/.env"
test -f "$APP_DIR/artisan"
cd "$APP_DIR"

php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $connection = (string) config("database.default");
    $cfg = (array) config("database.connections.".$connection);
    if (!in_array($connection, ["mysql", "mariadb"], true)) exit(2);
    $escape = static fn ($value): string => str_replace(["\\", "\n", "\r"], ["\\\\", "", ""], (string) $value);
    file_put_contents($argv[1], implode(PHP_EOL, [
        "[client]",
        "host=".$escape($cfg["host"] ?? "127.0.0.1"),
        "port=".$escape($cfg["port"] ?? "3306"),
        "user=".$escape($cfg["username"] ?? ""),
        "password=".$escape($cfg["password"] ?? ""),
        "default-character-set=utf8mb4",
    ]).PHP_EOL);
    chmod($argv[1], 0600);
    file_put_contents($argv[2], (string) ($cfg["database"] ?? ""));
' "$DB_CNF" "$DB_NAME_FILE"

DB_NAME="$(cat "$DB_NAME_FILE")"
test -n "$DB_NAME"

nice -n 10 ionice -c2 -n7 mysqldump \
    --defaults-extra-file="$DB_CNF" \
    --single-transaction \
    --quick \
    --routines \
    --events \
    --triggers \
    --hex-blob \
    --default-character-set=utf8mb4 \
    "$DB_NAME" | gzip -1 > "$STAGE_DIR/database.sql.gz"

rm -f "$DB_CNF" "$DB_NAME_FILE"

set +e
nice -n 10 ionice -c2 -n7 tar \
    --warning=no-file-changed \
    --ignore-failed-read \
    -czf "$STAGE_DIR/application.tar.gz" \
    -C /var/www skyguardian
APP_STATUS=$?
set -e
[ "$APP_STATUS" -le 1 ]

CONFIG_PATHS=(
    etc/nginx
    etc/letsencrypt
    etc/mysql
    etc/php/8.3/fpm/pool.d
)
for service in /etc/systemd/system/skyguardian*.service /etc/systemd/system/skyguardian*.timer; do
    [ -e "$service" ] && CONFIG_PATHS+=("${service#/}")
done
nice -n 10 ionice -c2 -n7 tar \
    --ignore-failed-read \
    -czf "$STAGE_DIR/server-config.tar.gz" \
    -C / "${CONFIG_PATHS[@]}"

crontab -l > "$STAGE_DIR/root-crontab.txt" 2>/dev/null || true
systemctl list-units 'skyguardian*' --all --no-pager > "$STAGE_DIR/systemd-status.txt" 2>/dev/null || true
printf 'created_utc=%s\napp_dir=%s\n' "$STAMP" "$APP_DIR" > "$STAGE_DIR/MANIFEST.txt"

(
    cd "$STAGE_DIR"
    sha256sum database.sql.gz application.tar.gz server-config.tar.gz > SHA256SUMS
)

tar -czf "$FINAL_ARCHIVE" -C "$STAGE_DIR" .
tar -tzf "$FINAL_ARCHIVE" >/dev/null
chmod 600 "$FINAL_ARCHIVE"

mapfile -t BACKUPS < <(find "$BACKUP_ROOT" -maxdepth 1 -type f -name 'skyguardian-full-*.tar.gz' -printf '%T@ %p\n' | sort -nr | cut -d' ' -f2-)
if [ "${#BACKUPS[@]}" -gt 14 ]; then
    printf '%s\0' "${BACKUPS[@]:14}" | xargs -0r rm -f
fi

if [ -f "$CONFIG_FILE" ]; then
    # shellcheck disable=SC1090
    source "$CONFIG_FILE"
fi

if [ -n "${RCLONE_REMOTE:-}" ]; then
    KEY_FILE="${BACKUP_ENCRYPTION_PASSWORD_FILE:-/etc/skyguardian/backup.key}"
    test -s "$KEY_FILE"
    command -v rclone >/dev/null
    ENCRYPTED="$FINAL_ARCHIVE.enc"
    openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 \
        -in "$FINAL_ARCHIVE" \
        -out "$ENCRYPTED" \
        -pass "file:$KEY_FILE"
    rclone copyto "$ENCRYPTED" "${RCLONE_REMOTE%/}/$(basename "$ENCRYPTED")"
    rm -f "$ENCRYPTED"
fi

sha256sum "$FINAL_ARCHIVE"

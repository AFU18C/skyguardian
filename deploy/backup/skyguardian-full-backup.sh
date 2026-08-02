#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APP_DIR="/var/www/skyguardian"
BACKUP_ROOT="/var/backups/skyguardian"
CONFIG_FILE="/etc/skyguardian/backup.env"
STATUS_ROOT="/var/lib/skyguardian-backup"
STATUS_FILE="$STATUS_ROOT/status.json"
LATEST_FILE="$STATUS_ROOT/latest.json"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
WORK_DIR="$BACKUP_ROOT/.work-$STAMP-$RANDOM"
STAGE_DIR="$WORK_DIR/stage"
FINAL_ARCHIVE="$BACKUP_ROOT/skyguardian-full-$STAMP.tar.gz"
DB_CNF="$WORK_DIR/mysql-client.cnf"
DB_NAME_FILE="$WORK_DIR/database-name.txt"

mkdir -p "$BACKUP_ROOT" "$STAGE_DIR" "$STATUS_ROOT"
chmod 700 "$BACKUP_ROOT"
chown root:www-data "$STATUS_ROOT"
chmod 750 "$STATUS_ROOT"

write_status() {
    local state="$1"
    local finished_at="$2"
    local finished_json="null"
    local temp_file="$STATUS_ROOT/.status-$STAMP-$RANDOM.json"

    if [ -n "$finished_at" ]; then
        finished_json="\"$finished_at\""
    fi

    printf '{"state":"%s","started_at":"%s","finished_at":%s}\n' \
        "$state" \
        "$STARTED_AT" \
        "$finished_json" \
        > "$temp_file"
    chown root:www-data "$temp_file"
    chmod 640 "$temp_file"
    mv -f "$temp_file" "$STATUS_FILE"
}

cleanup() {
    rm -f "$DB_CNF" "$DB_NAME_FILE"
    rm -rf "$WORK_DIR"
}

finish() {
    local result=$?

    if [ "$result" -ne 0 ]; then
        write_status "failed" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    fi
    cleanup
}
trap finish EXIT

write_status "running" ""

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

nice -n 10 ionice -c2 -n7 tar \
    --dereference \
    --exclude='skyguardian/storage/framework/cache/*' \
    --exclude='skyguardian/storage/framework/sessions/*' \
    --exclude='skyguardian/storage/framework/views/*' \
    --exclude='skyguardian/storage/logs/*' \
    -czf "$STAGE_DIR/application.tar.gz" \
    -C /var/www skyguardian

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
    -czf "$STAGE_DIR/server-config.tar.gz" \
    -C / "${CONFIG_PATHS[@]}"

crontab -l > "$STAGE_DIR/root-crontab.txt" 2>/dev/null || true
systemctl list-units 'skyguardian*' --all --no-pager > "$STAGE_DIR/systemd-status.txt" 2>/dev/null || true
printf 'created_utc=%s\napp_dir=%s\n' "$STAMP" "$APP_DIR" > "$STAGE_DIR/MANIFEST.txt"

(
    cd "$STAGE_DIR"
    sha256sum database.sql.gz application.tar.gz server-config.tar.gz > SHA256SUMS
    gzip -t database.sql.gz
    tar -tzf application.tar.gz > application-files.txt
    grep -Fx 'skyguardian/.env' application-files.txt >/dev/null
    grep -Fx 'skyguardian/artisan' application-files.txt >/dev/null
    grep -Fx 'skyguardian/composer.lock' application-files.txt >/dev/null
    grep -Eq '^skyguardian/storage/app(/|$)' application-files.txt
    tar -tzf server-config.tar.gz >/dev/null
)

tar -czf "$FINAL_ARCHIVE" -C "$STAGE_DIR" .
tar -tzf "$FINAL_ARCHIVE" >/dev/null

VERIFY_DIR="$WORK_DIR/verify"
mkdir -p "$VERIFY_DIR"
tar -xzf "$FINAL_ARCHIVE" -C "$VERIFY_DIR"
(
    cd "$VERIFY_DIR"
    sha256sum -c SHA256SUMS
    gzip -t database.sql.gz
    tar -tzf application.tar.gz >/dev/null
    tar -tzf server-config.tar.gz >/dev/null
)
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
    rclone check "$ENCRYPTED" "${RCLONE_REMOTE%/}/$(basename "$ENCRYPTED")" --one-way
    rm -f "$ENCRYPTED"
fi

sha256sum "$FINAL_ARCHIVE"

FINISHED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
ARCHIVE_SIZE="$(stat -c '%s' "$FINAL_ARCHIVE")"
LATEST_TEMP="$STATUS_ROOT/.latest-$STAMP-$RANDOM.json"
printf '{"created_at":"%s","archive":"%s","size_bytes":%s}\n' \
    "$FINISHED_AT" "$(basename "$FINAL_ARCHIVE")" "$ARCHIVE_SIZE" > "$LATEST_TEMP"
chown root:www-data "$LATEST_TEMP"
chmod 640 "$LATEST_TEMP"
mv -f "$LATEST_TEMP" "$LATEST_FILE"
write_status "success" "$FINISHED_AT"

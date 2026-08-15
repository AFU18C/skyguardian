#!/usr/bin/env bash
set -Eeuo pipefail

TARGET="/etc/nginx/conf.d/skyguardian-performance.conf"
WORK_FILE="$(mktemp)"
PREVIOUS_FILE="$(mktemp)"
HAD_PREVIOUS=0
INSTALLED=0

cleanup() {
    local result=$?

    if [ "$INSTALLED" -ne 1 ] && [ "$HAD_PREVIOUS" -eq 1 ]; then
        sudo install -o root -g root -m 644 "$PREVIOUS_FILE" "$TARGET"
    elif [ "$INSTALLED" -ne 1 ]; then
        sudo rm -f -- "$TARGET"
    fi
    rm -f "$WORK_FILE" "$PREVIOUS_FILE"

    return "$result"
}
trap cleanup EXIT

if sudo test -f "$TARGET"; then
    sudo cp "$TARGET" "$PREVIOUS_FILE"
    HAD_PREVIOUS=1
    sudo rm -f -- "$TARGET"
fi

NGINX_CONFIG="$(sudo nginx -T 2>&1)"

if ! grep -Eq '^[[:space:]]*server_tokens[[:space:]]+off;' <<< "$NGINX_CONFIG"; then
    if grep -Eq '^[[:space:]]*server_tokens[[:space:]]+on;' <<< "$NGINX_CONFIG"; then
        echo 'Nginx explicitly exposes its version; change server_tokens to off before deployment.' >&2
        exit 1
    fi
    printf '%s\n' 'server_tokens off;' >> "$WORK_FILE"
fi

if ! grep -Eq '^[[:space:]]*gzip[[:space:]]+on;' <<< "$NGINX_CONFIG"; then
    printf '%s\n' 'gzip on;' >> "$WORK_FILE"
fi
if ! grep -Eq '^[[:space:]]*gzip_vary[[:space:]]+on;' <<< "$NGINX_CONFIG"; then
    printf '%s\n' 'gzip_vary on;' >> "$WORK_FILE"
fi
if ! grep -Eq '^[[:space:]]*gzip_min_length[[:space:]]+' <<< "$NGINX_CONFIG"; then
    printf '%s\n' 'gzip_min_length 1024;' >> "$WORK_FILE"
fi
if ! grep -Eq '^[[:space:]]*gzip_comp_level[[:space:]]+' <<< "$NGINX_CONFIG"; then
    printf '%s\n' 'gzip_comp_level 5;' >> "$WORK_FILE"
fi
if ! grep -Eq '^[[:space:]]*gzip_types[[:space:]]+' <<< "$NGINX_CONFIG"; then
    printf '%s\n' 'gzip_types text/plain text/css application/json application/javascript application/xml image/svg+xml;' >> "$WORK_FILE"
fi

cat >> "$WORK_FILE" <<'NGINX'

map $uri $skyguardian_asset_expiry {
    default off;
    ~^/build/ max;
    ~^/storage/site/ 7d;
    ~^/favicon\.ico$ 7d;
}

expires $skyguardian_asset_expiry;
NGINX

sudo install -o root -g root -m 644 "$WORK_FILE" "$TARGET"
sudo nginx -t
INSTALLED=1

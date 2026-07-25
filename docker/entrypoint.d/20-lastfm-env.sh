#!/bin/sh
set -e

DATA_DIR="${APP_BASE_DIR}/data"

require_env() {
    for name in "$@"; do
        eval "value=\${$name:-}"
        if [ -z "$value" ]; then
            echo "❌ Missing required environment variable: $name" >&2
            exit 1
        fi
    done
}

require_env APP_URL LASTFM_API

if [ ! -f "$DATA_DIR/db/database.db" ]; then
    require_env ADMIN_USER ADMIN_PASSWORD
fi

KEY_FILE="$DATA_DIR/.app_key"

if [ -n "${APP_KEY:-}" ]; then
    if [ -f "$KEY_FILE" ] && [ "$APP_KEY" != "$(cat "$KEY_FILE")" ]; then
        echo "⚠️  APP_KEY differs from the one stored in data/.app_key — credentials encrypted with the previous key can no longer be decrypted." >&2
    fi
elif [ -f "$KEY_FILE" ]; then
    APP_KEY=$(cat "$KEY_FILE")
else
    APP_KEY=$(php "$APP_BASE_DIR/artisan" key:generate --show)
    (umask 077 && printf '%s' "$APP_KEY" > "$KEY_FILE")
    echo "✅ APP_KEY generated and persisted in data/.app_key"
fi

(umask 077 && printf 'APP_KEY=%s\n' "$APP_KEY" > "$APP_BASE_DIR/.env")

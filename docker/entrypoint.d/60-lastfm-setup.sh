#!/bin/sh
set -e

php "$APP_BASE_DIR/artisan" db:seed --class=AdminSeeder --force

if ! php "$APP_BASE_DIR/artisan" lastfm:import-legacy; then
    echo "⚠️  Legacy import skipped, see the message above. Starting the application anyway." >&2
fi

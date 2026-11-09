#!/bin/sh
set -e

php "$APP_BASE_DIR/artisan" db:seed --class=AdminSeeder --force

#!/bin/sh
set -e

DATA_DIR="${APP_BASE_DIR}/data"
DIRS="$DATA_DIR/db $DATA_DIR/cache/artists $DATA_DIR/logs $DATA_DIR/montage"

mkdir -p $DIRS 2>/dev/null || true

for dir in $DIRS; do
    if [ ! -w "$dir" ]; then
        echo "❌ $dir is missing or not writable." >&2
        echo "   The container runs as $(id -un) ($(id -u):$(id -g)) — chown the mounted volume to that UID/GID." >&2
        exit 1
    fi
done
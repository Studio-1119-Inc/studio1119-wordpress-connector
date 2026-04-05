#!/usr/bin/env bash
#
# Stops the `wp server` processes launched by start.sh. Leaves data intact.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

if [ ! -f "$PID_FILE" ]; then
    echo "no .pids file — nothing to stop"
    exit 0
fi

while IFS='|' read -r SLUG PID PORT; do
    if kill -0 "$PID" 2>/dev/null; then
        echo "stopping $SLUG (pid=$PID, port=$PORT)"
        kill "$PID" 2>/dev/null || true
    else
        echo "$SLUG (pid=$PID) already stopped"
    fi
done < "$PID_FILE"

rm -f "$PID_FILE"

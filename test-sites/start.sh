#!/usr/bin/env bash
#
# Launches `wp server` for each site in the background and records PIDs to
# .pids so stop.sh can clean up.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

require_tool wp

if [ -f "$PID_FILE" ]; then
    echo "existing .pids file found — run ./stop.sh first" >&2
    exit 1
fi

: > "$PID_FILE"

while IFS='|' read -r SLUG PORT DB SEO; do
    SITE_DIR="$(site_path "$SLUG")"
    if [ ! -f "$SITE_DIR/wp-config.php" ]; then
        echo "[$SLUG] not provisioned, skipping. Run ./setup.sh first."
        continue
    fi

    echo "[$SLUG] starting on http://localhost:$PORT"
    ( cd "$SITE_DIR" && PHP_CLI_SERVER_WORKERS=4 wp server --host=127.0.0.1 --port="$PORT" >/dev/null 2>&1 ) &
    echo "$SLUG|$!|$PORT" >> "$PID_FILE"
done < <(iter_sites)

sleep 1
echo ""
echo "Running sites:"
while IFS='|' read -r SLUG PID PORT; do
    if kill -0 "$PID" 2>/dev/null; then
        echo "  $SLUG  pid=$PID  http://localhost:$PORT/wp-admin"
    else
        echo "  $SLUG  pid=$PID  DIED — check wp-config.php and DB connection"
    fi
done < "$PID_FILE"
echo ""
echo "admin credentials: $CREDS_FILE"

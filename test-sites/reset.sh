#!/usr/bin/env bash
#
# Stops all sites, drops their databases, and deletes their files. Destroys
# the credentials file too. Next ./setup.sh starts from a clean slate.
#
# Asks for confirmation unless FORCE=1 is set.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

# mysql or mariadb client needed


if [ "${FORCE:-0}" != "1" ]; then
    echo "This will DELETE all test-site databases and files. Continue? [y/N]"
    read -r confirm
    case "$confirm" in
        y|Y|yes|YES) ;;
        *) echo "aborted"; exit 1 ;;
    esac
fi

if [ -f "$PID_FILE" ]; then
    "$SCRIPT_DIR/stop.sh"
fi

DB_USER="$(db_user)"
DB_CLIENT="$(db_cmd)"

while IFS='|' read -r SLUG PORT DB SEO; do
    echo "[$SLUG] dropping database '$DB'"
    $DB_CLIENT -u "$DB_USER" -e "DROP DATABASE IF EXISTS \`$DB\`;"
done < <(iter_sites)

if [ -d "$SITES_DIR" ]; then
    echo "removing $SITES_DIR"
    rm -rf "$SITES_DIR"
fi

rm -f "$CREDS_FILE"

echo "reset complete. run ./setup.sh to start fresh."

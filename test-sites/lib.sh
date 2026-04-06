#!/usr/bin/env bash
#
# Shared helpers sourced by every script in test-sites/.
#
# Sets:
#   TEST_SITES_DIR   absolute path to test-sites/
#   SITES_DIR        absolute path to test-sites/sites/
#   SITES_CONF       absolute path to test-sites/sites.conf
#   CREDS_FILE       absolute path to test-sites/.credentials
#   PID_FILE         absolute path to test-sites/.pids
#
# Provides:
#   require_tool <name>      Abort if <name> is not on PATH.
#   iter_sites               Reads sites.conf; exports SITE_SLUG, SITE_PORT, SITE_DB, SITE_SEO_PLUGIN per row.
#   admin_user               Echoes the admin username (default: admin).
#   admin_password           Echoes the admin password from .credentials, generating it on first call.
#   admin_email              Echoes the admin email (default: admin@test.local).
#   site_path <slug>         Echoes the absolute path to a site's WP install.
#   wp_cmd <slug> <args...>  Runs `wp` against the given site with --path and.

set -euo pipefail

TEST_SITES_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITES_DIR="$TEST_SITES_DIR/sites"
SITES_CONF="$TEST_SITES_DIR/sites.conf"
CREDS_FILE="$TEST_SITES_DIR/.credentials"
PID_FILE="$TEST_SITES_DIR/.pids"

require_tool() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "error: required tool '$1' is not installed. Run: brew install $1" >&2
        exit 1
    fi
}

iter_sites() {
    # Usage:
    #   while iter_sites_next; do ... $SITE_SLUG $SITE_PORT ... ; done
    # but we implement it as a simple function that prints lines the caller parses.
    grep -v '^#' "$SITES_CONF" | grep -v '^[[:space:]]*$'
}

admin_user() {
    echo "admin"
}

admin_email() {
    echo "admin@test.local"
}

admin_password() {
    if [ ! -f "$CREDS_FILE" ]; then
        local pw
        pw="$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)"
        umask 077
        printf 'ADMIN_USER=admin\nADMIN_PASSWORD=%s\nADMIN_EMAIL=admin@test.local\n' "$pw" > "$CREDS_FILE"
    fi
    # shellcheck disable=SC1090
    source "$CREDS_FILE"
    echo "$ADMIN_PASSWORD"
}

db_cmd() {
    # Use whichever MySQL-compatible client is available and working.
    # Try mysql first (works with both MySQL and MariaDB), then mariadb.
    local user="${DB_USER:-root}"
    for client in mysql mariadb; do
        if command -v "$client" >/dev/null 2>&1 && "$client" -u "$user" -e "SELECT 1" >/dev/null 2>&1; then
            echo "$client"
            return
        fi
    done
    echo "error: no working MySQL/MariaDB client found for user '$user'" >&2
    exit 1
}

db_user() {
    echo "root"
}

site_path() {
    echo "$SITES_DIR/$1"
}

wp_cmd() {
    local slug="$1"
    shift
    wp --path="$(site_path "$slug")" "$@"
}

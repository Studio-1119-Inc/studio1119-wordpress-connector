#!/usr/bin/env bash
#
# One-time setup: downloads WP core, creates databases, installs WordPress,
# installs WooCommerce and the per-site SEO plugin, and calls seed.sh to
# import products from the exported JSON.
#
# Idempotent per-site: re-running skips sites whose wp-config.php already
# exists. To wipe and start over, run ./reset.sh first.
#
# Usage:
#   ./setup.sh
#
# Prereqs: brew install php mariadb wp-cli jq && brew services start mariadb

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

require_tool wp
require_tool jq
require_tool php

# wp-cli's tarball extraction needs more than PHP's default 128M.
export WP_CLI_PHP_ARGS="-d memory_limit=512M"

ADMIN_USER="$(admin_user)"
ADMIN_PASSWORD="$(admin_password)"
ADMIN_EMAIL="$(admin_email)"
DB_USER="$(db_user)"
DB_CLIENT="$(db_cmd)"

mkdir -p "$SITES_DIR"

while IFS='|' read -r SLUG PORT DB SEO_PLUGIN; do
    SITE_DIR="$(site_path "$SLUG")"
    URL="http://localhost:$PORT"

    echo ""
    echo "=== [$SLUG] $URL ==="

    if [ -f "$SITE_DIR/wp-config.php" ]; then
        echo "[$SLUG] wp-config.php already exists — skipping. Run ./reset.sh to start over."
        continue
    fi

    mkdir -p "$SITE_DIR"

    echo "[$SLUG] creating database '$DB'..."
    $DB_CLIENT -u "$DB_USER" -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

    echo "[$SLUG] downloading WP core..."
    wp --path="$SITE_DIR" core download --quiet

    echo "[$SLUG] creating wp-config.php..."
    wp --path="$SITE_DIR" config create \
        --dbname="$DB" \
        --dbuser="$DB_USER" \
        --dbpass="" \
        --dbhost="127.0.0.1" \
        --skip-check \
        --quiet

    echo "[$SLUG] installing WordPress..."
    wp --path="$SITE_DIR" core install \
        --url="$URL" \
        --title="Test Site ($SLUG)" \
        --admin_user="$ADMIN_USER" \
        --admin_password="$ADMIN_PASSWORD" \
        --admin_email="$ADMIN_EMAIL" \
        --skip-email \
        --quiet

    echo "[$SLUG] installing WooCommerce..."
    wp --path="$SITE_DIR" plugin install woocommerce --activate --quiet

    if [ "$SEO_PLUGIN" != "none" ]; then
        echo "[$SLUG] installing SEO plugin: $SEO_PLUGIN..."
        wp --path="$SITE_DIR" plugin install "$SEO_PLUGIN" --activate --quiet
    else
        echo "[$SLUG] standalone mode — no SEO plugin"
    fi

    # WC creates its pages on first admin load; force it synchronously so
    # subsequent product imports don't race with it.
    wp --path="$SITE_DIR" wc --user="$ADMIN_USER" tool run install_pages >/dev/null 2>&1 || true

    # Pretty permalinks and shop as front page so products are visible
    # on the storefront immediately.
    wp --path="$SITE_DIR" option update permalink_structure '/%postname%/' --quiet
    wp --path="$SITE_DIR" option update show_on_front 'page' --quiet
    wp --path="$SITE_DIR" option update page_on_front "$(wp --path="$SITE_DIR" option get woocommerce_shop_page_id 2>/dev/null)" --quiet
    wp --path="$SITE_DIR" rewrite flush --quiet

    echo "[$SLUG] ready at $URL/wp-admin  (login: $ADMIN_USER)"
done < <(iter_sites)

echo ""
echo "All sites provisioned. Seeding products..."
"$SCRIPT_DIR/seed.sh"

echo ""
echo "Done. Credentials in $CREDS_FILE. Run ./start.sh to launch the dev servers."

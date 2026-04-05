#!/usr/bin/env bash
#
# Builds a fresh connector zip and installs it into all provisioned test sites.
# Uses --force so it replaces any previously-installed version in place.
#
# Usage:
#   ./install-connector.sh <app> <env> [version]
#
# Examples:
#   ./install-connector.sh cataseo development 0.1.0
#   ./install-connector.sh trusync development 0.1.0

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

require_tool wp

APP="${1:-}"
ENV="${2:-}"
VERSION="${3:-0.1.0}"

if [ -z "$APP" ] || [ -z "$ENV" ]; then
    echo "usage: $0 <app> <env> [version]" >&2
    exit 1
fi

echo "Building $APP ($ENV) v$VERSION..."
"$REPO_ROOT/build/build.sh" "$APP" "$ENV" "$VERSION" >/dev/null

PLUGIN_SLUG="$(jq -r ".apps.\"$APP\".plugin_slug" "$REPO_ROOT/apps.json")"
ZIP_PATH="$REPO_ROOT/dist/${PLUGIN_SLUG}-${VERSION}-${ENV}.zip"

if [ ! -f "$ZIP_PATH" ]; then
    echo "error: expected zip at $ZIP_PATH but it was not produced" >&2
    exit 1
fi

echo "Installing $ZIP_PATH into all sites..."

while IFS='|' read -r SLUG PORT DB SEO; do
    SITE_DIR="$(site_path "$SLUG")"
    if [ ! -f "$SITE_DIR/wp-config.php" ]; then
        echo "[$SLUG] not provisioned, skipping"
        continue
    fi
    echo "[$SLUG] installing $PLUGIN_SLUG"
    wp --path="$SITE_DIR" plugin install "$ZIP_PATH" --force --activate --quiet
done < <(iter_sites)

echo ""
echo "Connector installed in all sites. Reload /wp-admin to see the widget mount."

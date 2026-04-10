#!/usr/bin/env bash
#
# Per-app build script.
#
# Reads apps.json, substitutes tokens in src/, and produces a distributable
# zip file at dist/<plugin_slug>-<version>-<env>.zip that can be uploaded to
# a WordPress site or submitted to the WordPress.org plugin directory.
#
# Usage:
#   ./build/build.sh <app> <env> [version]
#
# Examples:
#   ./build/build.sh cataseo production 0.1.0
#   ./build/build.sh cataseo staging 0.1.0
#   ./build/build.sh trusync development 0.1.0
#
# Requires: jq, zip, sed
#

set -euo pipefail

APP="${1:-}"
ENV="${2:-}"
VERSION="${3:-0.1.0}"

if [ -z "$APP" ] || [ -z "$ENV" ]; then
    echo "Usage: $0 <app> <env> [version]" >&2
    echo "  app: one of the keys in apps.json (cataseo, trusync, ...)" >&2
    echo "  env: production | staging | development" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
APPS_JSON="$REPO_ROOT/apps.json"
SRC_DIR="$REPO_ROOT/src"
DIST_DIR="$REPO_ROOT/dist"

for bin in jq zip; do
    if ! command -v "$bin" >/dev/null 2>&1; then
        echo "error: '$bin' is required but not installed" >&2
        exit 1
    fi
done

if ! jq -e ".apps.\"$APP\"" "$APPS_JSON" >/dev/null; then
    echo "error: app '$APP' not found in apps.json" >&2
    exit 1
fi

read_field() {
    jq -r ".apps.\"$APP\".$1" "$APPS_JSON"
}

PLUGIN_NAME="$(read_field plugin_name)"
PLUGIN_SLUG="$(read_field plugin_slug)"
TEXT_DOMAIN="$(read_field text_domain)"
MENU_TITLE="$(read_field menu_title)"
MENU_ICON="$(read_field menu_icon)"
ROOT_ELEMENT_ID="$(read_field root_element_id)"
OPTION_PREFIX="$(read_field option_prefix)"
META_PREFIX="$(read_field meta_prefix)"
DOCS_URL="$(read_field docs_url)"
DESCRIPTION="$(read_field description)"
WIDGET_URL="$(jq -r ".apps.\"$APP\".environments.\"$ENV\"" "$APPS_JSON")"
CONST_PREFIX="$(echo "$OPTION_PREFIX" | tr '[:lower:]' '[:upper:]')"

if [ "$WIDGET_URL" = "null" ] || [ -z "$WIDGET_URL" ]; then
    echo "error: environment '$ENV' not found for app '$APP'" >&2
    exit 1
fi

BUILD_NAME="${PLUGIN_SLUG}-${VERSION}-${ENV}"
STAGING_DIR="$DIST_DIR/$BUILD_NAME"
OUT_ZIP="$DIST_DIR/${BUILD_NAME}.zip"

echo "Building $PLUGIN_NAME v$VERSION for $ENV ($WIDGET_URL)"

rm -rf "$STAGING_DIR" "$OUT_ZIP"
mkdir -p "$STAGING_DIR/$PLUGIN_SLUG"
cp -R "$SRC_DIR/." "$STAGING_DIR/$PLUGIN_SLUG/"

# The main plugin file is named after the slug for WordPress.org convention.
mv "$STAGING_DIR/$PLUGIN_SLUG/plugin.php" "$STAGING_DIR/$PLUGIN_SLUG/$PLUGIN_SLUG.php"

# Ship the license alongside the plugin.
cp "$REPO_ROOT/LICENSE" "$STAGING_DIR/$PLUGIN_SLUG/"

substitute() {
    local file="$1"
    sed \
        -e "s|{{APP_PLUGIN_NAME}}|$PLUGIN_NAME|g" \
        -e "s|{{APP_TEXT_DOMAIN}}|$TEXT_DOMAIN|g" \
        -e "s|{{APP_MENU_TITLE}}|$MENU_TITLE|g" \
        -e "s|{{APP_MENU_ICON}}|$MENU_ICON|g" \
        -e "s|{{APP_ROOT_ELEMENT_ID}}|$ROOT_ELEMENT_ID|g" \
        -e "s|{{APP_OPTION_PREFIX}}|$OPTION_PREFIX|g" \
        -e "s|{{APP_META_PREFIX}}|$META_PREFIX|g" \
        -e "s|{{APP_DOCS_URL}}|$DOCS_URL|g" \
        -e "s|{{APP_DESCRIPTION}}|$DESCRIPTION|g" \
        -e "s|{{APP_WIDGET_URL}}|$WIDGET_URL|g" \
        -e "s|{{APP_CONST_PREFIX}}|$CONST_PREFIX|g" \
        -e "s|{{APP_VERSION}}|$VERSION|g" \
        "$file" > "$file.tmp" && mv "$file.tmp" "$file"
}

while IFS= read -r -d '' file; do
    substitute "$file"
done < <(find "$STAGING_DIR/$PLUGIN_SLUG" \( -name '*.php' -o -name '*.txt' -o -name '*.md' \) -type f -print0)

( cd "$STAGING_DIR" && zip -r -q "$OUT_ZIP" "$PLUGIN_SLUG" )
rm -rf "$STAGING_DIR"

echo "built: $OUT_ZIP"

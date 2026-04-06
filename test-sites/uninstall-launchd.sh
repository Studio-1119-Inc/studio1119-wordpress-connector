#!/usr/bin/env bash
#
# Unloads and removes the launchd plists installed by install-launchd.sh.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

LAUNCH_AGENTS_DIR="$HOME/Library/LaunchAgents"
LABEL_PREFIX="com.studio1119.wp-test"

while IFS='|' read -r SLUG PORT DB SEO; do
    LABEL="${LABEL_PREFIX}.${SLUG}"
    PLIST="$LAUNCH_AGENTS_DIR/${LABEL}.plist"

    if [ -f "$PLIST" ]; then
        launchctl bootout "gui/$(id -u)/$LABEL" 2>/dev/null || true
        rm -f "$PLIST"
        echo "[$SLUG] unloaded and removed"
    else
        echo "[$SLUG] no plist found — skipping"
    fi
done < <(iter_sites)

echo ""
echo "All launchd plists removed. Sites will no longer start on login."

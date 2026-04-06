#!/usr/bin/env bash
#
# Installs launchd plists so each WP test site starts automatically on login.
# Survives reboots — no need to run ./start.sh manually.
#
# Usage:
#   ./install-launchd.sh          # install & load all sites
#   ./uninstall-launchd.sh        # unload & remove all plists
#
# The plists call `wp server` directly (not through start.sh) so there's no
# dependency on the .pids file. start.sh/stop.sh still work for ad-hoc use
# but you won't need them day-to-day once launchd is managing the processes.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

require_tool wp

WP_BIN="$(command -v wp)"
LAUNCH_AGENTS_DIR="$HOME/Library/LaunchAgents"
mkdir -p "$LAUNCH_AGENTS_DIR"

LABEL_PREFIX="com.studio1119.wp-test"

while IFS='|' read -r SLUG PORT DB SEO; do
    SITE_DIR="$(site_path "$SLUG")"
    if [ ! -f "$SITE_DIR/wp-config.php" ]; then
        echo "[$SLUG] not provisioned — skipping. Run ./setup.sh first."
        continue
    fi

    LABEL="${LABEL_PREFIX}.${SLUG}"
    PLIST="$LAUNCH_AGENTS_DIR/${LABEL}.plist"
    LOG_DIR="$SCRIPT_DIR/logs"
    mkdir -p "$LOG_DIR"

    cat > "$PLIST" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
  "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>${LABEL}</string>

    <key>ProgramArguments</key>
    <array>
        <string>${WP_BIN}</string>
        <string>server</string>
        <string>--host=127.0.0.1</string>
        <string>--port=${PORT}</string>
        <string>--docroot=${SITE_DIR}</string>
    </array>

    <key>WorkingDirectory</key>
    <string>${SITE_DIR}</string>

    <key>EnvironmentVariables</key>
    <dict>
        <key>PATH</key>
        <string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin</string>
        <key>WP_CLI_PHP_ARGS</key>
        <string>-d memory_limit=512M</string>
    </dict>

    <key>RunAtLoad</key>
    <true/>

    <key>KeepAlive</key>
    <true/>

    <key>StandardOutPath</key>
    <string>${LOG_DIR}/${SLUG}.log</string>

    <key>StandardErrorPath</key>
    <string>${LOG_DIR}/${SLUG}.err</string>
</dict>
</plist>
PLIST

    # Unload first if already loaded (idempotent)
    launchctl bootout "gui/$(id -u)/$LABEL" 2>/dev/null || true

    launchctl bootstrap "gui/$(id -u)" "$PLIST"
    echo "[$SLUG] loaded — http://localhost:$PORT/wp-admin"

done < <(iter_sites)

echo ""
echo "All sites registered with launchd. They will start automatically on login."
echo "Logs in $SCRIPT_DIR/logs/"
echo "To remove: ./uninstall-launchd.sh"

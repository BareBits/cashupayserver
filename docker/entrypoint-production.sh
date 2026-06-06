#!/bin/bash
# Production entrypoint for the CashuPayServer Docker image.
#
# Responsibilities:
#   1. Seed /var/www/html from /usr/src/cashupayserver on first boot.
#   2. Ensure data/ exists and the app dir is writable by www-data so the
#      in-app auto-updater can overlay files.
#   3. Start a backgrounded cron loop hitting cron.php every
#      CRON_INTERVAL_SECONDS seconds (default 60; set 0 to disable).
#   4. exec apache2-foreground so Apache becomes PID 1 and Docker can
#      signal/stop it cleanly.
set -euo pipefail

APP_SRC=/usr/src/cashupayserver
APP_DIR=/var/www/html

# 1. Seed the volume on first boot. We treat an empty directory as
#    "uninitialized" — once any file lives there we never overwrite, so
#    auto-updater changes and operator edits stick.
if [ -z "$(ls -A "$APP_DIR" 2>/dev/null || true)" ]; then
    echo "[cashupay] seeding $APP_DIR from $APP_SRC"
    cp -a "$APP_SRC/." "$APP_DIR/"
fi

# 2. Apache writes session files via the .htaccess'd app; the auto-updater
#    overlays the source tree. Both need www-data ownership.
mkdir -p "$APP_DIR/data"
chown -R www-data:www-data "$APP_DIR"

# 3. Background cron. set -u would trip on an unset var, so default first.
interval="${CRON_INTERVAL_SECONDS:-60}"
case "$interval" in
    ''|*[!0-9]*) interval=60 ;;
esac

if [ "$interval" -gt 0 ]; then
    echo "[cashupay] starting cron loop, interval=${interval}s"
    (
        # Give Apache a moment to bind before the first cron tick attempts
        # any HTTP-style work (webhook delivery, etc.).
        sleep 5
        while :; do
            su -s /bin/sh www-data -c "cd '$APP_DIR' && exec php cron.php" || true
            sleep "$interval"
        done
    ) &
else
    echo "[cashupay] CRON_INTERVAL_SECONDS=0, in-container cron disabled"
fi

# 4. Hand off to Apache (or whatever CMD the operator overrode).
exec "$@"

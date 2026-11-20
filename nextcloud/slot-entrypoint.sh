#!/bin/sh
set -eu
if [ "${STRATUS2_SLOT_MODE:-0}" != 1 ]; then
  exec /entrypoint.sh "$@"
fi
# Each slot has its own ephemeral application tree. Only config/data are shared.
# Slot startup must never run migrations or rewrite another slot's application.
test -f /var/www/html/config/config.php
tar -C /usr/src/nextcloud --exclude='./config' --exclude='./data' -cf - . | tar -C /var/www/html -xf -
mkdir -p /var/www/html/custom_apps
tar -C /opt/stratus2/apps --exclude='./stratus2_ai/js' --exclude='./stratus2_ai/css' -cf - . | tar -C /var/www/html/custom_apps -xf -
# Hashed client assets are append-only and shared across slots. An old page can
# still load its own JS/CSS after the traffic switch or rollback.
cp -Rn /opt/stratus2/apps/stratus2_ai/js/. /var/www/html/custom_apps/stratus2_ai/js/
cp -Rn /opt/stratus2/apps/stratus2_ai/css/. /var/www/html/custom_apps/stratus2_ai/css/
chown -R www-data:root /var/www/html/custom_apps
cp /usr/src/nextcloud/robots.txt /var/www/html/robots.txt
php -r '
  require "/var/www/html/version.php";
  $CONFIG=[];require "/var/www/html/config/config.php";
  if (empty($CONFIG["installed"]) || ($CONFIG["version"] ?? "")!==implode(".",$OC_Version)) {
    fwrite(STDERR,"Core version mismatch: perform a reviewed maintenance upgrade.\n");exit(1);
  }
'
su -p www-data -s /bin/sh -c 'php /var/www/html/occ status --output=json' > /tmp/stratus2-status.json
php -r '$s=json_decode(file_get_contents("/tmp/stratus2-status.json"),true);if (!$s["installed"] || $s["maintenance"] || ($s["needsDbUpgrade"] ?? true)) exit(1);'
if [ "${1:-}" = cron ]; then exec /cron.sh; fi
exec "$@"

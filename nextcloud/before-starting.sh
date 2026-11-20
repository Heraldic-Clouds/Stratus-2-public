#!/bin/sh
set -eu
ORIGIN="${STRATUS2_ORIGIN:-https://stratus.example.com}"
mkdir -p /var/www/html/custom_apps
cp -R /opt/stratus2/apps/. /var/www/html/custom_apps/
mkdir -p /var/www/html/stratus2-assets/js /var/www/html/stratus2-assets/css
cp -R /opt/stratus2/apps/stratus2_ai/js/. /var/www/html/stratus2-assets/js/
cp -R /opt/stratus2/apps/stratus2_ai/css/. /var/www/html/stratus2-assets/css/
cp /usr/src/nextcloud/robots.txt /var/www/html/robots.txt
cp /usr/src/nextcloud/config/stratus2.config.php /var/www/html/config/stratus2.config.php
cp /usr/src/nextcloud/config/primary-storage-guard.php /var/www/html/config/primary-storage-guard.php
occ() { php /var/www/html/occ "$@"; }
occ upgrade --no-interaction
occ app:enable stratus2_ai
occ app:enable stratus_transfer
occ background:cron
occ config:app:set files default_quota --value="${FREE_QUOTA:-1 GB}"
occ config:app:set theming name --value='Stratus-2'
occ config:app:set theming url --value="$ORIGIN"
occ config:app:set theming color --value='#126b7a'
slogan="$(occ config:app:get theming slogan 2>/dev/null || true)"
if [ -z "$slogan" ]; then
  occ config:app:set theming slogan --value='Your files. A clearer sky.'
fi
occ theming:config logo /opt/stratus2/apps/stratus2_ai/img/app.svg
if [ "$STRATUS2_TEST_MODE" != '1' ] && [ "${STRATUS2_LOCAL_AUTH:-0}" != 1 ]; then
  occ app:enable user_oidc
  occ user_oidc:provider Supabase --clientid="$SUPABASE_OIDC_CLIENT_ID" \
    --clientsecret-env=SUPABASE_OIDC_CLIENT_SECRET \
    --discoveryuri="$SUPABASE_URL/auth/v1/.well-known/openid-configuration" \
    --endsessionendpointuri="$ORIGIN/index.php/apps/stratus2_ai/auth/logout" \
    --mapping-uid=sub --mapping-email=email --mapping-display-name=email --unique-uid=1 --group-provisioning=0 \
    --scope='openid email profile' >/dev/null
  occ config:app:set user_oidc allow_multiple_user_backends --type=string --value=0
fi

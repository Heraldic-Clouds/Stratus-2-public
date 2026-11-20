FROM node:24-alpine AS assets
WORKDIR /build
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN corepack enable && corepack prepare pnpm@11.25.0 --activate && pnpm install --frozen-lockfile
COPY frontend ./frontend
COPY apps/stratus2_ai/js ./apps/stratus2_ai/js
COPY apps/stratus2_ai/css ./apps/stratus2_ai/css
COPY scripts/build_assets.mjs ./scripts/build_assets.mjs
RUN pnpm run build
RUN node scripts/build_assets.mjs apps/stratus2_ai

FROM composer:2.9 AS dependencies
WORKDIR /build
COPY apps/stratus2_ai/composer.json apps/stratus2_ai/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --ignore-platform-req=php

FROM nextcloud:35.0.2-apache
ARG USER_OIDC_VERSION=8.11.0
ADD https://github.com/nextcloud-releases/user_oidc/releases/download/v8.11.0/user_oidc-v8.11.0.tar.gz /tmp/user_oidc.tar.gz
RUN mkdir -p /opt/stratus2/apps && tar -xzf /tmp/user_oidc.tar.gz -C /opt/stratus2/apps && rm /tmp/user_oidc.tar.gz
COPY apps/stratus2_ai /opt/stratus2/apps/stratus2_ai
COPY apps/stratus_transfer /opt/stratus2/apps/stratus_transfer
COPY --from=dependencies /build/vendor /opt/stratus2/apps/stratus2_ai/vendor
COPY --from=assets /build/apps/stratus2_ai/js /opt/stratus2/apps/stratus2_ai/js
COPY --from=assets /build/apps/stratus2_ai/css /opt/stratus2/apps/stratus2_ai/css
COPY --from=assets /build/apps/stratus2_ai/asset-manifest.json /opt/stratus2/apps/stratus2_ai/asset-manifest.json
COPY --from=assets /build/apps/stratus_transfer/js/transfer.js /opt/stratus2/apps/stratus_transfer/js/transfer.js
COPY nextcloud/stratus2.config.php /usr/src/nextcloud/config/stratus2.config.php
COPY nextcloud/primary-storage-guard.php /usr/src/nextcloud/config/primary-storage-guard.php
COPY nextcloud/robots.txt /usr/src/nextcloud/robots.txt
COPY nextcloud/before-starting.sh /docker-entrypoint-hooks.d/before-starting/stratus2.sh
COPY nextcloud/health.conf /etc/apache2/conf-enabled/stratus2-health.conf
COPY nextcloud/robots.conf /etc/apache2/conf-enabled/stratus2-robots.conf
COPY nextcloud/photos.conf /etc/apache2/conf-enabled/stratus2-photos.conf
COPY nextcloud/slot-entrypoint.sh /usr/local/bin/stratus2-entrypoint
RUN chmod 0755 /docker-entrypoint-hooks.d/before-starting/stratus2.sh /usr/local/bin/stratus2-entrypoint && a2disconf remoteip && a2dismod remoteip && a2enmod headers
ENTRYPOINT ["/usr/local/bin/stratus2-entrypoint"]
CMD ["apache2-foreground"]
ENV PHP_MEMORY_LIMIT=1G PHP_UPLOAD_LIMIT=2G APACHE_BODY_LIMIT=0 NEXTCLOUD_UPDATE=1 STRATUS2_HEALTH_HOST=2strat.us

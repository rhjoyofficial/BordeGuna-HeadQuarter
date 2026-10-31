# syntax=docker/dockerfile:1
# BordeGuna-HeadQuarter application image.
# One image serves app (php-fpm), horizon and scheduler; the compose file picks the command.
# Built on the VPS with the repository root as the build context:
#   docker compose -f deploy/docker-compose.yml build app

# ---------- 1. Front-end assets ----------
FROM node:22-alpine AS assets
WORKDIR /build
# Baked into the bundle at build time (Inertia's page-title template reads VITE_APP_NAME).
ARG VITE_APP_NAME="BordeGuna HQ"
ENV VITE_APP_NAME=${VITE_APP_NAME}
COPY apps/hq/package.json apps/hq/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY apps/hq/ ./
RUN npm run build

# ---------- 2. PHP dependencies ----------
FROM composer:2 AS vendor
WORKDIR /build
COPY apps/hq/composer.json apps/hq/composer.lock ./
# --ignore-platform-reqs: the composer image lacks the runtime extensions; the runtime image below has them.
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-reqs

# ---------- 3. Runtime ----------
FROM php:8.3.36-fpm-alpine AS runtime

# ⭐ The commit this image was built from, stamped onto the image itself.
#
# `deploy.sh` reads it back off the RUNNING container to answer "what is actually released", instead
# of inferring it from the checkout. The checkout states intent; the label states fact, and the two
# can disagree - a refused deploy, a manual `git pull`, a detached HEAD. Recording intent as fact is
# how RELEASES.md came to say a release followed itself (docs/58).
#
# Empty by default so a plain `docker compose build` still works; deploy.sh always passes it.
ARG GIT_COMMIT=""
LABEL com.bordeguna.commit=$GIT_COMMIT
# ⭐ The same fact, where the APPLICATION can read it (docs/80 C.4). A container cannot read its own
# image label, so until this line the running app had no honest way to say what release it was.
# Empty means "not a release image": the app then says "development checkout" rather than guessing.
ENV HQ_RELEASE_COMMIT=$GIT_COMMIT

RUN apk add --no-cache bash curl postgresql17-client icu-libs libzip libpng libjpeg-turbo freetype tzdata \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql17-dev icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev linux-headers \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip gd opcache pcntl bcmath \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del .build-deps \
 && rm -rf /tmp/pear

COPY deploy/php.ini /usr/local/etc/php/conf.d/99-hq.ini

WORKDIR /var/www/html

# Application code, then vendor and built assets on top.
COPY apps/hq/ ./
COPY --from=vendor /build/vendor ./vendor
COPY --from=assets /build/public/build ./public/build

# public-volume is the mountpoint of the shared `public` named volume (served by the nginx sidecar). It must
# exist in the image and be owned by www-data so Docker initialises the volume with that ownership; otherwise
# the first start fails with "Permission denied" while republishing assets (seen on the first deploy, 5 Sep 2026).
RUN php artisan package:discover --ansi \
 && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache public-volume \
 && chown -R www-data:www-data storage bootstrap/cache public-volume \
 && chmod -R ug+rwX storage bootstrap/cache public-volume

COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

USER www-data
EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]

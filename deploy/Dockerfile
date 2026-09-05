# syntax=docker/dockerfile:1
# BordeGuna-HeadQuarter application image.
# One image serves app (php-fpm), horizon and scheduler; the compose file picks the command.
# Built on the VPS with the repository root as the build context:
#   docker compose -f deploy/docker-compose.yml build app

# ---------- 1. Front-end assets ----------
FROM node:22-alpine AS assets
WORKDIR /build
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
FROM php:8.3-fpm-alpine AS runtime

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

RUN php artisan package:discover --ansi \
 && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R ug+rwX storage bootstrap/cache

COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

USER www-data
EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]

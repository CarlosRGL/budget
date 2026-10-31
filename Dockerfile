# syntax=docker/dockerfile:1

# Production image for the Dokploy VPS (plan step 9).
#   1. build: PHP dependencies without dev packages, then the Vite build (the
#      Wayfinder plugin calls `php artisan`, so PHP and Bun share this stage).
#   2. app: nginx + PHP-FPM (serversideup), plus the queue worker and the
#      scheduler as s6 services in the same container. Migrations and the
#      Laravel caches run at start (AUTORUN_*).

FROM serversideup/php:8.5-cli AS build

USER root
COPY --from=oven/bun:1 /usr/local/bin/bun /usr/local/bin/bun

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY package.json bun.lock ./
RUN bun install --frozen-lockfile

COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction \
    && bun run build \
    && rm -rf node_modules

FROM serversideup/php:8.5-fpm-nginx AS app

ENV PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=true \
    TZ=Europe/Paris

USER root
COPY docker/s6/ /etc/s6-overlay/s6-rc.d/
RUN touch /etc/s6-overlay/user-bundles.d/user/contents.d/laravel-queue \
        /etc/s6-overlay/user-bundles.d/user/contents.d/laravel-scheduler

COPY --chown=www-data:www-data --from=build /app /var/www/html

USER www-data

# syntax=docker/dockerfile:1
#
# Coolify: Build Pack = Dockerfile, Ports Exposes = 8080, Healthcheck = /up
# Keep APP_* / DB_* as Runtime-only env vars (do not bake secrets into the image).

FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction \
    --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative


FROM serversideup/php:8.4-fpm-nginx

ENV PHP_OPCACHE_ENABLE=1 \
    SSL_MODE=off \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    AUTORUN_LARAVEL_CONFIG_CACHE=true \
    AUTORUN_LARAVEL_ROUTE_CACHE=true \
    AUTORUN_LARAVEL_VIEW_CACHE=true \
    AUTORUN_LARAVEL_EVENT_CACHE=true \
    AUTORUN_LARAVEL_STORAGE_LINK=true

USER root

# Laravel + MySQL essentials (image already ships common PHP extensions)
RUN install-php-extensions intl bcmath pcntl pdo_mysql

COPY --from=vendor --chown=www-data:www-data /app /var/www/html

# Ensure writable runtime directories exist even when gitignored contents are empty
RUN mkdir -p \
        storage/framework/{cache/data,sessions,views,testing} \
    storage/logs \
    storage/app/public \
    bootstrap/cache \
    hown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -f http://127.0.0.1:8080/up || exit 1

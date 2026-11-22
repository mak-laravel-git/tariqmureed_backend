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
    AUTORUN_LARAVEL_VIEW_CACHE=false \
    AUTORUN_LARAVEL_EVENT_CACHE=true \
    AUTORUN_LARAVEL_STORAGE_LINK=true

USER root

# Laravel + MySQL essentials (image already ships common PHP extensions)
RUN install-php-extensions intl bcmath pcntl pdo_mysql

COPY --from=vendor --chown=www-data:www-data /app /var/www/html

# Supervisor keeps `php artisan schedule:work` running (SportMonks auto sync). It is registered as an
# s6-overlay service, so the image init starts it next to nginx and php-fpm and the container's
# health follows the same rules as before. No Coolify "Scheduled Task" is needed any more.
RUN apt-get update \
    && apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /etc/s6-overlay/s6-rc.d/supervisord/dependencies.d /etc/s6-overlay/s6-rc.d/user/contents.d /etc/supervisor \
    && echo longrun > /etc/s6-overlay/s6-rc.d/supervisord/type \
    && printf '#!/command/with-contenv sh\nexec supervisord --nodaemon --configuration /etc/supervisor/supervisord.conf\n' > /etc/s6-overlay/s6-rc.d/supervisord/run \
    && chmod +x /etc/s6-overlay/s6-rc.d/supervisord/run \
    && touch /etc/s6-overlay/s6-rc.d/user/contents.d/supervisord

COPY supervisord.conf /etc/supervisor/supervisord.conf

# Headless API has no Blade templates, but `php artisan optimize` still needs this path.
# Also ensure writable runtime directories exist when gitignored contents are empty.
RUN mkdir -p \
    resources/views \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/testing \
    storage/logs \
    storage/app/public \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache resources/views \
    && chmod -R ug+rwx storage bootstrap/cache

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -f http://127.0.0.1:8080/up || exit 1

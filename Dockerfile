FROM serversideup/php:8.4-fpm-nginx

USER root

ENV APP_BASE_DIR=/app \
    NGINX_WEBROOT=/app/public \
    HEALTHCHECK_PATH=/up \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_STORAGE_LINK=false \
    AUTORUN_LARAVEL_MIGRATION_SKIP_DB_CHECK=true \
    PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_DATE_TIMEZONE=UTC

ENV APP_NAME=LastFM \
    APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=sqlite \
    SESSION_DRIVER=file \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync

RUN install-php-extensions gd intl

COPY --chmod=755 docker/entrypoint.d/ /etc/entrypoint.d/
COPY --chmod=755 docker/s6-overlay/ /etc/s6-overlay/
RUN touch /etc/s6-overlay/s6-rc.d/user/contents.d/laravel-scheduler

USER www-data
WORKDIR /app
COPY --chown=www-data:www-data app/ /app/

RUN composer install --no-interaction --no-dev --no-progress --optimize-autoloader \
    && php artisan filament:assets \
    && mkdir -p data/db data/cache/artists data/logs data/montage

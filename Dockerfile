FROM shinsenter/laravel:php8.4-nginx

ENV APP_PATH=/app \
    DOCUMENT_ROOT=public \
    TZ=UTC \
    ENABLE_TUNING_FPM=1 \
    DISABLE_AUTORUN_SCRIPTS=0 \
    COMPOSER_OPTIMIZE_AUTOLOADER=1 \
    LARAVEL_ENABLE_SCHEDULER=1 \
    LARAVEL_AUTO_MIGRATION=1 \
    LARAVEL_ENABLE_QUEUE_WORKER=0 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

COPY app/ ${APP_PATH}/
WORKDIR ${APP_PATH}

RUN composer config platform.php-64bit 8.4 \
    && composer install --no-interaction --optimize-autoloader --no-dev \
    && php artisan filament:assets

COPY startup/* /startup/
COPY hooks/onready/* ${APP_PATH}/hooks/onready/
RUN chmod +x /startup/* ${APP_PATH}/hooks/onready/* \
    && chown -R www-data:www-data ${APP_PATH}

EXPOSE 80

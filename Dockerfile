FROM shinsenter/php:8.4-fpm-nginx

ENV ENABLE_CRONTAB=1
ENV APP_PATH=/app
ENV DOCUMENT_ROOT=public
ENV TZ=UTC
ENV ENABLE_TUNING_FPM=1
ENV DISABLE_AUTORUN_SCRIPTS=0

ARG CURL_IMPERSONATE_VERSION=v0.6.1
RUN set -eux; \
    arch="$(dpkg --print-architecture)"; \
    case "$arch" in \
        amd64) ci_arch=x86_64 ;; \
        arm64) ci_arch=aarch64 ;; \
        *) echo "Unsupported arch: $arch"; exit 1 ;; \
    esac; \
    tmp="$(mktemp -d)"; \
    curl -fsSL -o "$tmp/ci.tar.gz" \
        "https://github.com/lwthiker/curl-impersonate/releases/download/${CURL_IMPERSONATE_VERSION}/curl-impersonate-${CURL_IMPERSONATE_VERSION}.${ci_arch}-linux-gnu.tar.gz"; \
    tar -xzf "$tmp/ci.tar.gz" -C /usr/local/bin/; \
    chmod +x /usr/local/bin/curl_chrome* /usr/local/bin/curl_edge* /usr/local/bin/curl_ff* /usr/local/bin/curl_safari* 2>/dev/null || true; \
    rm -rf "$tmp"; \
    /usr/local/bin/curl_chrome116 --version >/dev/null

COPY app/ ${APP_PATH}/
WORKDIR ${APP_PATH}

RUN composer config platform.php-64bit 8.4 && \
    composer install --no-interaction --optimize-autoloader --no-dev

COPY crontab /etc/crontab.d/lastfm
RUN chmod 0644 /etc/crontab.d/lastfm

COPY /startup/* /startup/
RUN chmod +x /startup/*

RUN chown -R www-data:www-data ${APP_PATH} && \
    chmod -R 755 ${APP_PATH}

EXPOSE 80
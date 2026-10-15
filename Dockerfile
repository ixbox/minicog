# syntax=docker/dockerfile:1

FROM php:8.4-cli-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# 依存だけ先に入れてレイヤーキャッシュを効かせる
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader \
    && rm -rf /root/.composer/cache

COPY public ./public
COPY src ./src
COPY templates ./templates
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

RUN mkdir -p /data && chown www-data:www-data /data
VOLUME /data

ENV MINICOG_DATA_FILE=/data/minicog.json \
    MINICOG_BASE_URL=http://localhost:9229 \
    PHP_CLI_SERVER_WORKERS=4

USER www-data
EXPOSE 9229

HEALTHCHECK --interval=10s --timeout=3s --start-period=3s --retries=3 \
    CMD wget -qO- http://127.0.0.1:9229/health || exit 1

CMD ["php", "-S", "0.0.0.0:9229", "public/index.php"]

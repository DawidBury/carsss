FROM php:8.4-fpm-alpine AS runtime_base

RUN apk add --no-cache icu-libs libpq libzip rabbitmq-c \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev rabbitmq-c-dev \
    && docker-php-ext-install -j"$(nproc)" intl opcache pcntl pdo_pgsql zip \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && pecl clear-cache \
    && apk del .build-deps

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
WORKDIR /app

FROM runtime_base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN set -eu; \
    for attempt in 1 2 3 4 5; do \
        if composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress; then \
            exit 0; \
        fi; \
        sleep $((attempt * 5)); \
    done; \
    exit 1

FROM runtime_base AS application
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && mkdir -p var/cache var/log var/share \
    && chown -R www-data:www-data var

FROM application AS api
EXPOSE 9000
CMD ["php-fpm"]

FROM runtime_base AS development
RUN apk add --no-cache git \
    && apk add --no-cache --virtual .xdebug-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && pecl clear-cache \
    && apk del .xdebug-deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.dev.ini /usr/local/etc/php/conf.d/99-dev.ini
CMD ["php-fpm"]

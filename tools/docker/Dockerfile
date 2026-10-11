# Reproducible PHP toolchain for local checks. Build args select the exact PHP pin.
ARG PHP_VERSION=8.3.35
FROM php:${PHP_VERSION}-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install zip pcntl bcmath sockets \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && rm -rf /var/lib/apt/lists/*

# Infection and PHPStan need more than the 128M CLI default.
RUN echo "memory_limit=1G" > "$PHP_INI_DIR/conf.d/zz-reqtide.ini"

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    COMPOSER_VENDOR_DIR=/opt/reqtide-vendor

WORKDIR /app

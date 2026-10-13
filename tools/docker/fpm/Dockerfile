# PHP-FPM for the FPM lane (tools/check-fpm.sh). Build args select the exact PHP pin, as in the CLI image.
ARG PHP_VERSION=8.4.26
FROM php:${PHP_VERSION}-fpm

RUN docker-php-ext-install bcmath sockets

# One pool of six workers that keeps the environment (the Redis host is passed through it) and logs to the container.
COPY pool.conf /usr/local/etc/php-fpm.d/zz-reqtide.conf

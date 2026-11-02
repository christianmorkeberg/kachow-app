# PHP 8.4 FPM for Kachow. The same image runs the web workers ("php") and the cron jobs ("cron").
FROM php:8.4-fpm-alpine

COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache tzdata \
 && install-php-extensions pdo_mysql mbstring opcache sodium gmp bcmath gd zip intl \
 && cp /usr/share/zoneinfo/Europe/Copenhagen /etc/localtime && echo "Europe/Copenhagen" > /etc/timezone

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-kachow.ini
COPY docker/crontab /etc/crontabs/root

WORKDIR /var/www/app

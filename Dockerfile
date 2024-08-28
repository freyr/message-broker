FROM php:8.3.10-cli-alpine3.20 as os
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN apk --no-cache add  \
    unzip  \
    && rm -rf /var/cache/apk/* \
    && install-php-extensions \
        opcache \
        amqp \
        sockets \
        zip \
        pcov \
        xdebug-3.3.1 \
        gmp \
        pcntl \
    && docker-php-source delete \
    && rm -rf /tmp/*

ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer/composer:2-bin /composer /usr/bin/composer
WORKDIR /app

FROM os as qa
COPY . .
RUN composer install

FROM php:8.4-cli-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --no-cache git unzip && \
    install-php-extensions \
        intl \
        mbstring \
        opcache \
        pcntl \
        pcov \
        pdo_pgsql \
        sockets \
        zip

# pcov collects line coverage for mutation testing (Infection); limited to src/.
RUN printf 'pcov.directory=/app/src\n' > /usr/local/etc/php/conf.d/zz-pcov.ini

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

WORKDIR /app
CMD ["php", "-a"]

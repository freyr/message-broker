ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --no-cache git unzip && \
    install-php-extensions \
        intl \
        mbstring \
        opcache \
        pcntl \
        pcov \
        pdo_pgsql \
        redis \
        sockets \
        zip

# pcov collects line coverage for mutation testing (Infection): the src/ folders of the core and of the
# adapter folders, so the whole repo without vendor/ (the path packages in vendor/ are symlinks; PHP
# reports the real path of each file).
RUN printf 'pcov.directory=/app\npcov.exclude="~/vendor/~"\n' > /usr/local/etc/php/conf.d/zz-pcov.ini

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

WORKDIR /app
CMD ["php", "-a"]

FROM php:8.4-cli-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# rdkafka pulls in librdkafka (build + runtime) via the extension installer
RUN apk add --no-cache git unzip && \
    install-php-extensions \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        rdkafka \
        sockets \
        zip

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

WORKDIR /app
CMD ["php", "-a"]

FROM php:8.4-cli-alpine
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    oniguruma-dev \
    $PHPIZE_DEPS && \
    install-php-extensions \
        redis \
        amqp \
        apcu \
        sockets \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        zip

# Install Composer
COPY --from=composer/composer:2-bin /composer /usr/bin/composer

# Set working directory
WORKDIR /app
CMD ["php", "-a"]

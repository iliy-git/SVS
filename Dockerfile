FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    sqlite-dev \
    linux-headers \
    curl \
    nodejs \
    npm

RUN docker-php-ext-install pdo_mysql pdo_sqlite mbstring exif pcntl bcmath gd intl

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

ARG USER_ID=1000
ARG GROUP_ID=1000
RUN addgroup -g ${GROUP_ID} iliy && \
    adduser -u ${USER_ID} -G iliy -s /bin/sh -D iliy

WORKDIR /var/www


RUN chown -R iliy:iliy /var/www

USER iliy

EXPOSE 9000
CMD ["php-fpm"]

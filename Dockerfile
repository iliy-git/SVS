FROM composer:2.7 as vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --ignore-platform-reqs \
    --no-interaction \
    --no-plugins \
    --no-scripts \
    --prefer-dist \
    --no-dev

FROM node:22-alpine as frontend
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
COPY resources/ ./resources/
RUN npm install && npm run build

FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    libpng-dev \
    libzip-dev \
    zip \
    icu-dev \
    oniguruma-dev \
    sqlite-dev \
    linux-headers

RUN docker-php-ext-install pdo_mysql pdo_sqlite mbstring exif pcntl bcmath gd intl

RUN addgroup -g 1000 iliy && adduser -u 1000 -G iliy -s /bin/sh -D iliy

WORKDIR /var/www

COPY --chown=iliy:iliy . .
COPY --from=vendor --chown=iliy:iliy /app/vendor/ ./vendor/
COPY --from=frontend --chown=iliy:iliy /app/public/build/ ./public/build/

RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p storage/app/private/livewire-tmp \
    && mkdir -p database \
    && chown -R iliy:iliy storage database bootstrap/cache \
    && chmod -R 775 storage database bootstrap/cache

USER iliy

EXPOSE 9000
CMD ["php-fpm"]

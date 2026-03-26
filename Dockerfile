# === ЭТАП 1: Сборка PHP зависимостей ===
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

# === ЭТАП 2: Сборка Frontend (JS/CSS) ===
FROM node:22-alpine as frontend
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
COPY resources/ ./resources/
RUN npm install && npm run build

# === ЭТАП 3: Финальный образ ===
FROM php:8.3-fpm-alpine

# Устанавливаем только необходимые системные расширения (минималистично)
RUN apk add --no-cache \
    libpng-dev \
    libzip-dev \
    zip \
    icu-dev \
    oniguruma-dev \
    sqlite-dev \
    linux-headers

RUN docker-php-ext-install pdo_mysql pdo_sqlite mbstring exif pcntl bcmath gd intl

# Настраиваем пользователя (Безопасность)
# В альпине используем id 1000, который обычно совпадает с хостом
RUN addgroup -g 1000 iliy && adduser -u 1000 -G iliy -s /bin/sh -D iliy

WORKDIR /var/www

# Копируем только то, что нужно
COPY --chown=iliy:iliy . .
# Заменяем папки вендоров и билда на те, что собрали в первых этапах
COPY --from=vendor --chown=iliy:iliy /app/vendor/ ./vendor/
COPY --from=frontend --chown=iliy:iliy /app/public/build/ ./public/build/

# Создаем структуру папок и ставим права ОДНИМ слоем (уменьшает размер образа)
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p storage/app/private/livewire-tmp \
    && mkdir -p database \
    && chown -R iliy:iliy storage database bootstrap/cache \
    && chmod -R 775 storage database bootstrap/cache

USER iliy

EXPOSE 9000
CMD ["php-fpm"]

FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip sqlite3 libsqlite3-dev ca-certificates gnupg

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

RUN useradd -u 1000 -ms /bin/bash iliy || true
RUN chown -R iliy:iliy /var/www

USER iliy

EXPOSE 8000
CMD ["php-fpm"]

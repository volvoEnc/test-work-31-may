FROM php:8.4-fpm-alpine AS php-base

RUN apk add --no-cache \
    bash \
    curl \
    git \
    icu-dev \
    libzip-dev \
    linux-headers \
    mysql-client \
    nginx \
    oniguruma-dev \
    unzip \
    zip \
    && docker-php-ext-install \
    bcmath \
    intl \
    mbstring \
    pcntl \
    pdo_mysql \
    sockets \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM php-base AS vendor

COPY composer.json composer.lock ./

RUN composer install \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --no-scripts \
    --no-autoloader

COPY . .

RUN composer dump-autoload --optimize

FROM node:22-alpine AS frontend

WORKDIR /var/www/html

COPY package.json package-lock.json ./

RUN npm ci

COPY resources resources
COPY public public
COPY tsconfig.json vite.config.ts ./

RUN npm run build

FROM php-base AS app

COPY --from=vendor /var/www/html /var/www/html
COPY --from=frontend /var/www/html/public/build /var/www/html/public/build
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

RUN mkdir -p \
    /run/nginx \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && touch .env \
    && chown -R www-data:www-data storage bootstrap/cache public/build

CMD ["php-fpm"]

ARG PHP_VERSION=8.3
ARG NODE_VERSION=22

FROM node:${NODE_VERSION}-bookworm-slim AS frontend

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN npm run build

FROM php:${PHP_VERSION}-fpm-bookworm AS app

ENV APP_ENV=production \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gosu \
        libcurl4-openssl-dev \
        libcurl4 \
        libfcgi-bin \
        libfreetype6-dev \
        libfreetype6 \
        libgmp-dev \
        libgmp10 \
        libicu-dev \
        libicu72 \
        libjpeg62-turbo-dev \
        libjpeg62-turbo \
        libmagickwand-dev \
        libmagickwand-6.q16-6 \
        libonig-dev \
        libonig5 \
        libpng-dev \
        libpng16-16 \
        libxml2-dev \
        libxml2 \
        libzip-dev \
        libzip4 \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        dom \
        exif \
        gd \
        gmp \
        intl \
        mbstring \
        pcntl \
        pdo_mysql \
        simplexml \
        soap \
        xml \
        xmlreader \
        xmlwriter \
        zip \
    && pecl install imagick-3.8.1 \
    && docker-php-ext-enable imagick \
    && apt-get purge -y --auto-remove \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libgmp-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libmagickwand-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY . .
COPY --from=frontend /build/public/build ./public/build

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader \
    && php artisan package:discover --ansi \
    && composer clear-cache

COPY docker/production/php.ini /usr/local/etc/php/conf.d/99-aureuserp.ini
COPY docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/zz-aureuserp.conf
COPY docker/production/entrypoint.sh /usr/local/bin/aureuserp-entrypoint

RUN chmod +x /usr/local/bin/aureuserp-entrypoint \
    && mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
    && rm -rf public/storage \
    && ln -s ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["aureuserp-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:1.28-alpine AS web

WORKDIR /var/www/html

COPY --from=app /var/www/html/public ./public
COPY docker/production/nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

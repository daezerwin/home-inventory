# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: install PHP dependencies
# ---------------------------------------------------------------------------
# Built on the runner's own platform: vendor/ is plain PHP, identical for every
# target architecture, so there's no need to run Composer under QEMU.
FROM --platform=$BUILDPLATFORM composer:2 AS vendor
WORKDIR /app

COPY composer.json composer.lock ./
COPY database ./database
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-reqs

# ---------------------------------------------------------------------------
# Stage 2: runtime image (Apache + PHP, with a queue worker for photos)
# ---------------------------------------------------------------------------
# No frontend build step: the stylesheet and script in public/ are plain files.
FROM php:8.4-apache AS app

ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        supervisor \
        unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite \
        mbstring \
        bcmath \
        zip \
        xml \
        dom \
        curl \
        gd \
        opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/inventory.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/inventory.conf

WORKDIR /var/www/html

COPY . .
COPY --from=vendor /app/vendor ./vendor

# Regenerate the package-discovery cache against this --no-dev vendor install;
# a cache copied in from local dev would reference dev-only packages.
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && php artisan package:discover --ansi

# The SQLite file lives outside the app tree (/var/lib/home-inventory), never
# inside database/: that directory holds migrations, which must always come
# fresh from the image rather than from a stale volume.
RUN mkdir -p /var/lib/home-inventory \
        storage/app/public/products \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data /var/lib/home-inventory storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/inventory-entrypoint
RUN chmod +x /usr/local/bin/inventory-entrypoint

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=120s \
    CMD curl -fsS http://localhost/up || exit 1

ENTRYPOINT ["inventory-entrypoint"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]

# syntax=docker/dockerfile:1
# Laravel API image. The same image runs php-fpm (app), the queue workers and the scheduler.
FROM php:8.3-fpm-bookworm

# PHP extensions used by the API.
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl gd zip opcache pcntl \
 && rm -rf /var/lib/apt/lists/*

# The official MySQL 8.4 client, same version as the database: the nightly backup calls
# mysqldump with --set-gtid-purged, which MariaDB's client (Debian's default) rejects.
COPY --from=mysql:8.4 /usr/bin/mysqldump /usr/bin/mysql /usr/local/bin/

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-erp.ini

WORKDIR /var/www/html

# Dependencies first so code changes do not reinstall them.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev --no-interaction \
 && php artisan package:discover --ansi \
 && ln -sfn ../storage/app/public public/storage \
 && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/db-check.php /usr/local/bin/erp-db-check.php
COPY docker/php/entrypoint.sh /usr/local/bin/erp-entrypoint
# Strip Windows line endings in case git or an editor added them.
RUN sed -i 's/\r$//' /usr/local/bin/erp-entrypoint && chmod +x /usr/local/bin/erp-entrypoint

ENTRYPOINT ["erp-entrypoint"]
CMD ["php-fpm"]

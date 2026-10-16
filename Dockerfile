# Fixzy Kriptobot — production image
# Web UI + API served by PHP built-in server. SQLite data lives on a volume.
FROM php:8.5-cli-alpine

# sqlite-dev + icu-dev + gmp-dev wajib untuk pdo_sqlite/intl/gmp di Alpine (ccxt perlukan bcmath+gmp)
RUN apk add --no-cache sqlite-dev icu-dev gmp-dev \
    && docker-php-ext-install pdo_sqlite bcmath intl gmp

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist \
    && composer dump-autoload --optimize --no-dev

COPY . .

# Writable dirs (mount volumes over these in compose)
RUN mkdir -p /app/database /app/storage/logs

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]

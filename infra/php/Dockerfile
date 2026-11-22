# Shared PHP image for api/ and business/ (build context: the app directory).
FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer

FROM php:8.6.0RC3-fpm-alpine@sha256:481010776c273096841ff202f56e6c98ffbdf7ca41483d389b6a3dfa5b236ebe AS base
RUN apk add --no-cache icu-libs libpq libzip fcgi \
    && apk add --no-cache --virtual .build $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql zip \
    && apk del .build
COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer
WORKDIR /app

FROM base AS dev
RUN apk add --no-cache --virtual .build $PHPIZE_DEPS linux-headers \
    && pecl install pcov && docker-php-ext-enable pcov \
    && apk del .build
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

FROM base AS prod
ARG HEALTH_PATH=/api/health
ENV APP_ENV=prod APP_DEBUG=0 HEALTH_PATH=${HEALTH_PATH}
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'opcache.validate_timestamps=0\nexpose_php=Off\n' > "$PHP_INI_DIR/conf.d/zz-prod.ini"
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction
COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && APP_SECRET=build-only bin/console cache:warmup \
    && mkdir -p var && chown -R www-data:www-data var \
    && rm /usr/bin/composer
USER www-data
HEALTHCHECK --interval=15s --timeout=3s --retries=3 CMD SCRIPT_NAME=/index.php SCRIPT_FILENAME=/app/public/index.php REQUEST_URI="$HEALTH_PATH" REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q '"status":"ok"'

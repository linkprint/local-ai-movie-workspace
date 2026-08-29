# syntax=docker/dockerfile:1.7
FROM php:8.4-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pcntl pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY images/portal/php.ini /usr/local/etc/php/conf.d/zz-movie.ini
COPY images/portal/php-fpm.conf /usr/local/etc/php-fpm.d/zz-movie.conf

FROM php-base AS composer-build
COPY --from=composer:2.9.7 /usr/bin/composer /usr/bin/composer
WORKDIR /srv/app
COPY app/composer.json app/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts
COPY app/ ./
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
RUN php scripts/validate-ui-translations.php
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && php artisan package:discover --ansi \
    && php artisan livewire:publish --assets --ansi

FROM node:24-bookworm-slim AS assets-build
WORKDIR /srv/app
COPY app/package.json app/package-lock.json ./
RUN npm ci --ignore-scripts
COPY --from=composer-build /srv/app /srv/app
RUN npm run build

FROM php-base AS runtime
WORKDIR /srv/app
COPY --from=composer-build --chown=www-data:www-data /srv/app /srv/app
COPY --from=assets-build --chown=www-data:www-data /srv/app/public/build /srv/app/public/build
COPY images/portal/entrypoint.sh /usr/local/bin/movie-entrypoint
RUN chmod 0755 /usr/local/bin/movie-entrypoint \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data:www-data
ENTRYPOINT ["movie-entrypoint"]
CMD ["php-fpm", "-F"]

FROM caddy:2.10-alpine AS gateway
COPY gateway/Caddyfile /etc/caddy/Caddyfile
COPY --from=runtime --chown=1000:1000 /srv/app/public /srv/app/public
RUN setcap -r /usr/bin/caddy
USER 1000:1000

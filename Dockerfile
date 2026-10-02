# syntax=docker/dockerfile:1

# ---------- base: PHP-FPM with the extensions the app needs ----------
FROM php:8.4-fpm-alpine AS base

# install-php-extensions pulls build deps, compiles and cleans up in one step
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql redis zip pcntl opcache

# Run as a non-root user; UID/GID match the host user so bind mounts stay writable in dev
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} app && adduser -D -u ${UID} -G app app \
    && sed -i '/^user = /d; /^group = /d' /usr/local/etc/php-fpm.d/www.conf

COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-app.ini
WORKDIR /var/www/html

# ---------- dev: code is bind-mounted, composer available ----------
FROM base AS dev
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
USER app

# ---------- vendor: production dependencies (cached until composer files change) ----------
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction

# ---------- assets: compiled frontend ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---------- prod: self-contained image ----------
FROM base AS prod
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
COPY --from=vendor --chown=app:app /var/www/html ./
COPY --from=assets --chown=app:app /app/public/build ./public/build
USER app
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

# ---------- web: nginx with the public files baked in ----------
FROM nginx:stable-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=prod /var/www/html/public /var/www/html/public

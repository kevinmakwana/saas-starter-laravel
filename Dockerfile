# syntax=docker/dockerfile:1.7
#
# Production image: PHP-FPM + nginx (non-root, port 8080). The same image runs three roles; only the command differs:
#   web        (default)             serves the app
#   worker     php artisan horizon   Redis queues
#   scheduler  php artisan schedule:work
# See docs/deploy.md.

# 1. Production PHP dependencies (no dev packages). Platform checks are skipped here: the final image has the extensions.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts

# 2. Frontend build. It imports Ziggy from vendor/, so the PHP dependencies are copied in.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# 3. Runtime.
FROM serversideup/php:8.4-fpm-nginx AS app

# Shown in Sentry as the release, so errors map to a commit.
ARG GIT_SHA=unknown
ENV SENTRY_RELEASE=${GIT_SHA} \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stack \
    LOG_STACK=json \
    PHP_OPCACHE_ENABLE=1

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

# Registers the packages. Config, route and view caches are built at release time (docs/deploy.md)
# because they depend on the environment the container runs in.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && php artisan package:discover --ansi

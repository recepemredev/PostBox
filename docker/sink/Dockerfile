# syntax=docker/dockerfile:1

# The load sink. A separate image from the backend on purpose: it is a test
# double with its own composer.json, and sharing an image would put Octane in
# the production application's dependency tree for no reason (dependencies.md
# records laravel/octane as "load sink only, not the main application").

ARG PHP_VERSION=8.4

# --- base -------------------------------------------------------------------
# FrankenPHP rather than the php-fpm base the backend uses. Octane needs an
# application server, and this is the one that ships as an official image
# instead of a pecl build assembled at build time — the sink's ceiling is a
# published number, so its runtime is pinned to a digest rather than compiled
# fresh on every build.
FROM dunglas/frankenphp:1-php${PHP_VERSION}-alpine AS base

# pcntl is not optional: Octane's own signal handling references SIGINT and
# friends unconditionally, and without the extension the server does not start
# a degraded way — it fatals on an undefined constant before it binds a port.
RUN install-php-extensions opcache pcntl

WORKDIR /app

# --- vendor -----------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY sink/composer.json sink/composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --no-progress

COPY sink/ ./

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# --- prod -------------------------------------------------------------------
FROM base AS prod

COPY docker/sink/php.ini /usr/local/etc/php/conf.d/zz-sink.ini

COPY --from=vendor --chown=www-data:www-data /app /app

RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8000

CMD ["php", "artisan", "octane:start", \
     "--server=frankenphp", \
     "--host=0.0.0.0", \
     "--port=8000"]

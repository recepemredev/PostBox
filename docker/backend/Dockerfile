# syntax=docker/dockerfile:1

# PHP-FPM image for every PHP service in the stack: the API, the workers, the
# scheduler and the one-shot migrate job. One image, four commands.

ARG PHP_VERSION=8.4

# --- base -------------------------------------------------------------------
# Extensions only. Nothing application-specific, so the layer is shared by every
# stage below and rebuilt only when the PHP version or an extension changes.
FROM php:${PHP_VERSION}-fpm-alpine AS base

RUN apk add --no-cache \
        icu-libs \
        libpq \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        linux-headers \
        postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        sockets \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# php-fpm's master process normally runs as root so it can drop privileges per
# worker. The pool user directives are commented out and the whole process runs
# as www-data instead, so no process in this image is ever root.
RUN sed -i 's/^user = www-data/;user = www-data/; s/^group = www-data/;group = www-data/' \
        /usr/local/etc/php-fpm.d/www.conf

# Explicit pool sizing (Step 15 Phase C, D136) — see the file's own comment
# for the arithmetic. Named to sort after the base image's own
# zz-docker.conf, so these pm.* directives are the ones that win.
COPY docker/backend/www.conf /usr/local/etc/php-fpm.d/zz-postbox-pool.conf

COPY docker/backend/entrypoint.sh /usr/local/bin/postbox-entrypoint
RUN chmod +x /usr/local/bin/postbox-entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["postbox-entrypoint"]
CMD ["php-fpm"]

# --- dev --------------------------------------------------------------------
# The source tree is bind-mounted and vendor/ lives in a named volume, so nothing
# is copied in. Composer is present because the entrypoint installs dependencies
# into the empty volume on a fresh stack.
FROM base AS dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/backend/php.dev.ini /usr/local/etc/php/conf.d/zz-postbox.ini

ENV COMPOSER_HOME=/tmp/composer

# A named volume inherits the ownership of the directory it shadows. The bind
# mounted source tree is read-only to the container user, so every path the
# framework writes to at runtime is a volume, and each one is created here as
# www-data so the non-root process can write into it.
RUN mkdir -p \
        /var/www/html/vendor \
        /var/www/html/bootstrap/cache \
        /var/www/html/storage/framework/cache/data \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html

USER www-data

# --- vendor -----------------------------------------------------------------
# Production dependencies only. This stage owns Composer; the runtime stage below
# copies the resulting tree and never sees Composer itself.
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY backend/composer.json backend/composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --no-progress

COPY backend/ ./

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# --- prod -------------------------------------------------------------------
FROM base AS prod

COPY docker/backend/php.prod.ini /usr/local/etc/php/conf.d/zz-postbox.ini

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html

# Framework caches are written at boot, not baked in: they embed environment
# values, and this image is built once and run in every environment. The runtime
# directories are recreated here because .dockerignore keeps their contents out
# of the build context.
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

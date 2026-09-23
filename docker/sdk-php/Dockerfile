# syntax=docker/dockerfile:1

# CLI-only image for sdk/php's own tooling (Pint, PHPStan, Pest). Never part of
# compose.prod.yaml — the SDK is a package consumers install into their own
# project, not something this application serves.

ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-cli-alpine AS dev

# Mirrors the host's own repo/sdk/php nesting exactly, rather than the flat
# /app every other image uses: tests/Pest.php locates the shared conformance
# vectors with dirname(__DIR__, 3), a path relative to its own file, so the
# container's contract mount has to sit exactly as many levels above the
# source tree as it does on the host (compose.yaml mounts ./contract at
# /workspace/contract, a sibling of /workspace/sdk).
WORKDIR /workspace/sdk/php

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/sdk-php/entrypoint.sh /usr/local/bin/postbox-entrypoint
RUN chmod +x /usr/local/bin/postbox-entrypoint

ENV COMPOSER_HOME=/tmp/composer

# A named volume inherits the ownership of the directory it shadows. The bind
# mounted source tree is read-only to the container user, so vendor/ — the one
# path this image writes to — is created here as www-data.
RUN mkdir -p vendor && chown -R www-data:www-data /workspace/sdk/php

USER www-data

ENTRYPOINT ["postbox-entrypoint"]
CMD ["composer", "--version"]

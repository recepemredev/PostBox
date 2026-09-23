#!/bin/sh
set -e

# vendor/ is a named volume, so it is empty on a fresh stack.
if [ ! -f vendor/autoload.php ]; then
    echo "postbox: installing composer dependencies into the vendor volume"
    composer install --no-interaction --no-progress
fi

exec "$@"

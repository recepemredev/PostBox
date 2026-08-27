#!/bin/sh
set -e

# Outside production, vendor/ is a named volume and therefore empty on a fresh
# stack. Only the one-shot `migrate` service reaches this branch — every
# long-running PHP service waits for that service to complete — so two containers
# can never install into the volume at the same time.
if [ "${APP_ENV}" != "production" ] && [ ! -f vendor/autoload.php ]; then
    echo "postbox: installing composer dependencies into the vendor volume"
    composer install --no-interaction --no-progress
fi

exec "$@"

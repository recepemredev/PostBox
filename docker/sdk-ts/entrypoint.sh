#!/bin/sh
set -e

# node_modules is a named volume, so it is empty on a fresh stack.
if [ ! -d node_modules/typescript ]; then
    echo "postbox: installing npm dependencies into the node_modules volume"
    npm ci --no-audit --no-fund
fi

exec "$@"

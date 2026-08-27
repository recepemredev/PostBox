#!/bin/sh
set -e

# node_modules is a named volume in development, so it is empty on a fresh stack.
# The frontend is the only service that mounts it, so there is no race here.
if [ ! -d node_modules/next ]; then
    echo "postbox: installing npm dependencies into the node_modules volume"
    npm ci --no-audit --no-fund
fi

exec "$@"

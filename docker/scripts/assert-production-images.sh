#!/usr/bin/env bash
# Asserts that no development dependency and no root process reaches a production
# image. A multi-stage Dockerfile that quietly copies the wrong stage still builds,
# still runs, and still passes every test — this is the check that would notice.
set -euo pipefail

BACKEND_IMAGE="${BACKEND_IMAGE:-postbox-backend:prod}"
FRONTEND_IMAGE="${FRONTEND_IMAGE:-postbox-frontend:prod}"

failures=0

fail() {
    echo "FAIL: $*" >&2
    failures=$((failures + 1))
}

assert_absent() {
    local image="$1" path="$2"

    if docker run --rm --entrypoint sh "$image" -c "test -e '$path'"; then
        fail "$image contains $path"
    else
        echo "  ok  $image has no $path"
    fi
}

assert_non_root() {
    local image="$1"
    local uid

    uid="$(docker run --rm --entrypoint sh "$image" -c 'id -u')"

    if [ "$uid" = "0" ]; then
        fail "$image runs as root"
    else
        echo "  ok  $image runs as uid $uid"
    fi
}

echo "backend image: $BACKEND_IMAGE"
for path in \
    vendor/bin/pest \
    vendor/pestphp \
    vendor/larastan \
    vendor/laravel/pint \
    vendor/phpstan \
    vendor/mockery \
    vendor/dedoc \
    /usr/bin/composer
do
    assert_absent "$BACKEND_IMAGE" "$path"
done
assert_non_root "$BACKEND_IMAGE"

echo "frontend image: $FRONTEND_IMAGE"
for path in \
    node_modules/eslint \
    node_modules/typescript \
    node_modules/tailwindcss \
    node_modules/.bin/next \
    node_modules/openapi-typescript \
    node_modules/vitest \
    node_modules/@testing-library \
    node_modules/jsdom
do
    assert_absent "$FRONTEND_IMAGE" "$path"
done
assert_non_root "$FRONTEND_IMAGE"

if [ "$failures" -gt 0 ]; then
    echo "$failures production image assertion(s) failed" >&2
    exit 1
fi

echo "production images carry no development dependency and run as non-root"

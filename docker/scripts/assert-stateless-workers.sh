#!/usr/bin/env bash
# Asserts that the three worker services are stateless and horizontally
# scalable.
#
# assert-scheduler-singleton.sh proves the one constraint that must NOT hold
# for a worker — this is its mirror, proving the constraints that must: no
# fixed name, no published port, no local state, and --scale actually works.
# "Stateless" is worth nothing if it is only written in a document, so this
# asserts the topology itself, against the profile that ships: a worker's
# storage volume exists in development for local iteration (backend_storage,
# shared with every other PHP service by design), and would be exactly the
# local state this script means to rule out if it survived into production.
#
# It creates containers without starting them and tears the project down
# again, so run it against a stopped stack. CI runs it before the stack is
# brought up, alongside assert-scheduler-singleton.sh.
set -euo pipefail

COMPOSE=(docker compose -f compose.yaml -f compose.prod.yaml)
WORKERS=(worker-deliveries worker-retries worker-maintenance)

echo "asserting the workers are stateless and scalable"

cleanup() {
    "${COMPOSE[@]}" down --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

config="$("${COMPOSE[@]}" config)"

service_block() {
    local name="$1"
    awk -v svc="  ${name}:" '
        $0 == svc {inside=1; print; next}
        /^  [a-zA-Z]/ {inside=0}
        inside {print}
    ' <<<"$config"
}

declare -A environments

for worker in "${WORKERS[@]}"; do
    block="$(service_block "$worker")"

    if [ -z "$block" ]; then
        echo "FAIL: ${worker} is not defined in the production profile" >&2
        exit 1
    fi

    if grep -q 'container_name:' <<<"$block"; then
        echo "FAIL: ${worker} declares a fixed container name" >&2
        exit 1
    fi

    if grep -q 'published:' <<<"$block"; then
        echo "FAIL: ${worker} publishes a host port" >&2
        exit 1
    fi

    if grep -qE '^\s*volumes:' <<<"$block"; then
        echo "FAIL: ${worker} carries a volume — that is local state" >&2
        exit 1
    fi

    if grep -qE '^\s*(deploy:|replicas:)' <<<"$block"; then
        echo "FAIL: ${worker} declares a replica count — that belongs to the scheduler alone" >&2
        exit 1
    fi

    environment="$(grep -oE -- '--environment=[a-z]+' <<<"$block" || true)"

    if [ -z "$environment" ]; then
        echo "FAIL: ${worker} does not name a Horizon --environment" >&2
        exit 1
    fi

    environments["$environment"]="${environments[$environment]:-0}"
    environments["$environment"]=$((environments["$environment"] + 1))

    echo "  ok  ${worker} has no fixed name, no published port, no volumes, no replica count, and runs ${environment}"
done

for environment in "${!environments[@]}"; do
    if [ "${environments[$environment]}" -gt 1 ]; then
        echo "FAIL: more than one worker runs ${environment} — scaling one queue's capacity would scale another's too" >&2
        exit 1
    fi
done

echo "  ok  each worker's Horizon environment is unique"

# The mirror of assert-scheduler-singleton.sh's own check: scaling stays
# deliberate rather than accidentally broad if the scheduler is still the
# only PHP service with a fixed name.
scheduler_block="$(service_block scheduler)"

if ! grep -q 'container_name: postbox-scheduler' <<<"$scheduler_block"; then
    echo "FAIL: the scheduler no longer has a fixed container name" >&2
    exit 1
fi
echo "  ok  the scheduler remains the only PHP service with a fixed name"

# The runtime proof: the roadmap's own claim is --scale worker=5, and the
# earlier per-block checks only prove the topology permits scaling in
# principle. This is the scale the assertion actually has to survive.
if ! "${COMPOSE[@]}" up --no-start \
    --scale worker-deliveries=5 \
    --scale worker-retries=3 \
    --scale worker-maintenance=2 \
    >/dev/null 2>&1; then
    echo "FAIL: the workers refused to scale to 5, 3 and 2 replicas" >&2
    exit 1
fi

echo "  ok  --scale worker-deliveries=5 --scale worker-retries=3 --scale worker-maintenance=2 was accepted"

echo "the workers are stateless and scalable"

#!/usr/bin/env bash
# Asserts that the scheduler cannot be scaled.
#
# A second scheduler would double every periodic dispatch — every retry sweep,
# every partition rotation. The constraint is worth nothing if it is only written
# in a document, so this asserts the topology actually refuses.
#
# It creates containers without starting them and tears the project down again,
# so run it against a stopped stack. CI runs it before the stack is brought up.
set -euo pipefail

COMPOSE=(docker compose -f compose.yaml -f compose.prod.yaml)

echo "asserting the scheduler refuses to scale"

cleanup() {
    "${COMPOSE[@]}" down --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

if "${COMPOSE[@]}" up --no-start --scale scheduler=2 >/dev/null 2>&1; then
    echo "FAIL: the scheduler accepted --scale scheduler=2" >&2
    exit 1
fi

echo "  ok  --scale scheduler=2 was rejected"

# The counterpart: the worker carries none of those constraints and must scale.
# An assertion that only proves the scheduler refuses would also pass if the
# whole topology refused.
if ! "${COMPOSE[@]}" up --no-start --scale worker=3 >/dev/null 2>&1; then
    echo "FAIL: the worker refused --scale worker=3" >&2
    exit 1
fi

echo "  ok  --scale worker=3 was accepted"

# The runtime refusal above is the real assertion; these confirm the two topology
# properties that produce it, so a future edit that removes one is caught here
# rather than in production behaviour.
scheduler_block="$("${COMPOSE[@]}" config \
    | awk '/^  scheduler:/ {inside=1; next} /^  [a-z]/ {inside=0} inside {print}')"

if ! grep -q 'container_name: postbox-scheduler' <<<"$scheduler_block"; then
    echo "FAIL: the scheduler no longer has a fixed container name" >&2
    exit 1
fi
echo "  ok  the scheduler has a fixed container name"

if ! grep -q 'replicas: 1' <<<"$scheduler_block"; then
    echo "FAIL: the scheduler no longer declares replicas: 1" >&2
    exit 1
fi
echo "  ok  the scheduler declares replicas: 1"

if grep -q 'published:' <<<"$scheduler_block"; then
    echo "FAIL: the scheduler publishes a host port" >&2
    exit 1
fi
echo "  ok  the scheduler publishes no host port"

echo "the scheduler is a singleton"

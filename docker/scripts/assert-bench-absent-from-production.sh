#!/usr/bin/env bash
# Asserts the guarantee Step 10 makes about how it resolved D75.
#
# AddressGuard refuses every private, loopback and link-local address on the
# delivery path, and a container on Docker's default network is inside one of
# them — so the load sink was unreachable. The resolution was not an allowlist
# in the application: a configured escape hatch that can reach production is
# the SSRF protection deleting itself. The bench network is given
# 203.0.113.0/24 (TEST-NET-3, RFC 5737) instead, which the guard already
# treats as ordinary.
#
# That makes "cannot be active in production" a structural property rather
# than a conditional one — the network lives in compose.bench.yaml, and the
# production profile never composes that file. Structural is only worth more
# than conditional if something checks it, which is this script (D84).
set -euo pipefail

failures=0

fail() {
    echo "FAIL: $*" >&2
    failures=$((failures + 1))
}

production_config="$(docker compose -f compose.yaml -f compose.prod.yaml config)"

# The sink itself, the network it lives on, and the subnet that network
# carries. Each is checked separately: a partial leak — the network defined
# but no service on it, say — is still a leak, and a single grep for one of
# the three would miss the other two.
for marker in 'sink' '203.0.113' 'bench'; do
    if printf '%s' "$production_config" | grep -qi "$marker"; then
        fail "the production profile mentions '$marker'"
    else
        echo "  ok  production profile has no '$marker'"
    fi
done

# The reverse direction: the overlay is only ever layered on the development
# base, so a runner that quietly started composing it with compose.prod.yaml
# would be caught here rather than by a benchmark that mysteriously worked.
for runner in Makefile task.ps1; do
    if grep -n 'compose.prod.yaml' "$runner" | grep -q 'compose.bench.yaml'; then
        fail "$runner composes the bench overlay with the production profile"
    else
        echo "  ok  $runner never composes bench with prod"
    fi
done

if [ "$failures" -gt 0 ]; then
    echo "$failures bench isolation assertion(s) failed" >&2
    exit 1
fi

echo "the benchmark overlay cannot be active in the production profile"

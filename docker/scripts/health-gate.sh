#!/bin/sh
# Polls the deep health check until it reports every dependency healthy.
#
# Runs inside the compose network (the `healthgate` service), so the local
# `prod-up` verification and the CI image job execute exactly this script — a
# second implementation of "is it up yet" would be a second thing to keep true.
#
# Exit 0 once the endpoint answers 200 with status "ok"; exit 1 if it never does.
set -eu

URL="${HEALTH_URL:-http://nginx:8080/api/health}"
ATTEMPTS="${HEALTH_ATTEMPTS:-60}"
INTERVAL="${HEALTH_INTERVAL:-2}"

attempt=1
while [ "$attempt" -le "$ATTEMPTS" ]; do
    if body="$(curl --fail --silent --show-error "$URL" 2>/dev/null)"; then
        case "$body" in
            *'"status":"ok"'*)
                echo "health gate: healthy after ${attempt} attempt(s)"
                echo "$body"
                exit 0
                ;;
        esac
    fi

    attempt=$((attempt + 1))
    sleep "$INTERVAL"
done

echo "health gate: ${URL} did not report healthy within $((ATTEMPTS * INTERVAL))s" >&2
curl --silent --show-error --include "$URL" >&2 || true
exit 1

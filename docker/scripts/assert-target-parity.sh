#!/usr/bin/env bash
# Asserts that the Makefile and task.ps1 expose the same targets.
#
# Two task runners exist because Windows has no make and CI has no PowerShell.
# They are thin — the behaviour is in the compose files — but "thin" is not
# "self-correcting": a target added to one and forgotten in the other means the
# documented interface is true on one platform only. This is the check that keeps
# the pair honest.
set -euo pipefail

targets_declared_in() {
    grep -m1 '^# TARGETS:' "$1" \
        | sed 's/^# TARGETS: *//' \
        | tr ' ' '\n' \
        | sed '/^$/d' \
        | sort
}

make_targets="$(targets_declared_in Makefile)"
ps_targets="$(targets_declared_in task.ps1)"

if [ "$make_targets" != "$ps_targets" ]; then
    echo "FAIL: Makefile and task.ps1 do not expose the same targets" >&2
    diff <(echo "$make_targets") <(echo "$ps_targets") >&2 || true
    exit 1
fi

# A declared target that neither file actually implements would pass the
# comparison above, so each one is checked against its own dispatcher.
while read -r target; do
    if ! grep -qE "^\.PHONY:.*\b${target}\b" Makefile || ! grep -qE "^${target}:" Makefile; then
        echo "FAIL: Makefile declares '${target}' but does not implement it" >&2
        exit 1
    fi

    if ! grep -qE "^\s*'${target}' \{" task.ps1; then
        echo "FAIL: task.ps1 declares '${target}' but does not implement it" >&2
        exit 1
    fi
done <<<"$make_targets"

echo "Makefile and task.ps1 expose the same targets:"
echo "$make_targets" | tr '\n' ' '
echo

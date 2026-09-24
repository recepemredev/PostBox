#!/usr/bin/env bash
# Asserts every tracked Markdown file, other than CLAUDE.md, is self-contained:
# it references no path that a clone of this repository does not actually have.
#
# CLAUDE.md is the one deliberate exception (D23): it is the operating contract,
# and it points into .claude/docs/ on purpose — that directory is untracked by
# design, and CLAUDE.md says so. Every other Markdown file is written for a
# reviewer who has only what `git clone` gave them, so a path it names has to
# resolve there too.
#
# This project references files in prose, inside backticks — `docs/adr/0001`,
# `../../contract/openapi.json` — never with Markdown's own [text](path) link
# syntax, so that is the surface this script actually checks, in two passes:
# the literal string `.claude/` appearing at all (the exact class of bug that
# prompted this script — a doc pointing a reader at the internal working
# record), and a backtick-quoted path that looks rooted at a real tracked
# top-level directory, or relative to the file's own directory, but is not
# there once you follow it. A plain `test -f` would pass against an untracked
# file sitting on the machine that wrote the check, which is exactly the
# false negative this script exists to rule out — every path is resolved
# against `git ls-files`, the tracked tree, not the working tree.
set -euo pipefail

fail=0

is_tracked() {
    # A tracked file matches by name; a tracked directory has at least one
    # file somewhere under it. Both are "real" as far as a clone is concerned.
    if [ -n "$(git ls-files -- "$1")" ]; then
        return 0
    fi

    # An ADR is referenced by its number alone in prose (`docs/adr/0001`),
    # matching this directory's own docs/adr/NNNN-slug.md naming rather than
    # forcing every mention to spell out a slug that exists to be readable,
    # not to be typed. Git's own pathspec matching does the globbing; the
    # quoting keeps the shell from doing it first.
    [ -n "$(git ls-files -- "$1"'*')" ]
}

check_file() {
    local file="$1"
    local dir
    dir="$(dirname "$file")"

    if grep -q '\.claude/' "$file"; then
        echo "FAIL: $file references the untracked .claude/ directory:" >&2
        grep -n '\.claude/' "$file" >&2
        fail=1
    fi

    local path resolved
    while IFS= read -r path; do
        [ -z "$path" ] && continue

        case "$path" in
            ../* | ./*)
                resolved="$(cd "$dir" && realpath -m --relative-to="$(git rev-parse --show-toplevel)" "$path" 2>/dev/null || true)"
                ;;
            *)
                resolved="$path"
                ;;
        esac

        if [ -z "$resolved" ] || ! is_tracked "$resolved"; then
            echo "FAIL: $file references untracked path: $path" >&2
            fail=1
        fi
    done < <(
        grep -oE '`(backend|contract|docker|docs|frontend|load|sdk|sink)/[A-Za-z0-9._/-]*`' "$file" | tr -d '`'
        grep -oE '`\.\.?/[A-Za-z0-9._/-]*`' "$file" | tr -d '`'
    )
}

while IFS= read -r file; do
    [ "$file" = "CLAUDE.md" ] && continue
    check_file "$file"
done < <(git ls-files '*.md')

if [ "$fail" -ne 0 ]; then
    exit 1
fi

echo "assert-doc-links: every tracked Markdown file references only tracked paths."

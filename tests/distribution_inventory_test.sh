#!/usr/bin/env bash
# Regression: a same-version checkout with changed/missing code is not a match.
set -eu
set -o pipefail
cd "$(dirname "$0")/.."
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
git archive HEAD src | tar -xf - -C "$tmp"
check() {
    local actual=0
    bash tests/distribution_inventory.sh "$2" "$tmp/src" >"$tmp/result" 2>&1 || actual=$?
    if [[ "$actual" != "$1" ]]; then
        cat "$tmp/result" >&2
        exit 1
    fi
}
check 0 HEAD
printf '\n' >> "$tmp/src/includes/trash.php"
check 1 HEAD
grep -Fxq 'DIFFERENT includes/trash.php' "$tmp/result"
rm "$tmp/src/admin/drop_id.php"
check 1 HEAD
grep -Fxq 'MISSING/UNREADABLE admin/drop_id.php' "$tmp/result"
check 2 refs/nonexistent/phase-0
echo 'PASS: matching, changed, missing and unknown-reference inventory cases'

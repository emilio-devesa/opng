#!/usr/bin/env bash
# Offline phase-0 triage. Run from the audit checkout, not the installed tree.
# Exit 0: selected files match; 1: different/missing; 2: invalid invocation.
set -eu
if [[ $# != 2 || ! -d "$2" ]]; then
    echo "Usage: bash tests/distribution_inventory.sh REVISION INSTALLED_SRC" >&2
    exit 2
fi
root=$(git -C "$(dirname "$0")" rev-parse --show-toplevel) || exit 2
rev=$(git -C "$root" rev-parse --verify --end-of-options "$1^{commit}") || exit 2
printf 'Reference: %s\n' "$rev"
status=0
# bootstrap distinguishes the release from the post-release checkout; the
# four access-control files alone are identical in these two distributions.
for file in bootstrap.php includes/version.php api/v1/index.php includes/entry_access.php admin/drop_id.php includes/trash.php; do
    expected=$(git -C "$root" rev-parse --verify "$rev:src/$file") || exit 2
    if [[ ! -f "$2/$file" || ! -r "$2/$file" ]]; then
        printf 'MISSING/UNREADABLE %s\n' "$file"
        status=1
    elif [[ $(git hash-object --no-filters -- "$2/$file") == "$expected" ]]; then
        printf 'MATCH %s\n' "$file"
    else
        printf 'DIFFERENT %s\n' "$file"
        status=1
    fi
done
exit "$status"

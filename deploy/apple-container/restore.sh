#!/bin/sh
# Open Pastebin NG - restore a backup into the running container (F3).
#
# Usage:
#   ./deploy/apple-container/restore.sh ./backup.sql [--force]
#   ./deploy/apple-container/restore.sh /backups/opng.sql [--force]
#
# A local file is copied into /backups first. The optional <file>.sha256
# sidecar is copied and verified automatically. Without --force the restore
# tool asks you to type RESTORE. This overwrites the tables in the dump.
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
app_container="${OPNG_APP_CONTAINER:-opng-app}"
file=""
force=0

usage() {
    cat <<'EOT'
Usage: restore.sh FILE [--force]

  FILE       Local SQL file or an existing /backups/... path in the container
  --force    Skip the interactive RESTORE confirmation (automation only)
EOT
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --force)
            force=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        -*)
            echo "ERROR: unknown argument: $1" >&2
            usage >&2
            exit 2
            ;;
        *)
            [ -z "$file" ] || { echo "ERROR: only one FILE is accepted." >&2; exit 2; }
            file="$1"
            shift
            ;;
    esac
done

[ -n "$file" ] || { usage >&2; exit 2; }

command -v container >/dev/null 2>&1 || {
    echo "ERROR: the Apple 'container' CLI is not installed." >&2
    exit 1
}
container exec "$app_container" true >/dev/null 2>&1 || {
    echo "ERROR: container ${app_container} is not running. Start it with start.sh." >&2
    exit 1
}

if [ -f "$file" ]; then
    base="$(basename "$file")"
    remote="/backups/$base"
    echo "Copying ${file} to ${app_container}:${remote}"
    container cp "$file" "${app_container}:${remote}"
    checksum=""
    if [ -f "${file}.sha256" ]; then
        container cp "${file}.sha256" "${app_container}:${remote}.sha256"
        checksum="--checksum=${remote}.sha256"
    fi
else
    remote="$file"
    checksum=""
    if container exec "$app_container" test -f "${remote}.sha256" >/dev/null 2>&1; then
        checksum="--checksum=${remote}.sha256"
    fi
fi

set -- php tools/restore.php --file="$remote"
if [ -n "$checksum" ]; then
    set -- "$@" "$checksum"
fi
if [ "$force" -eq 1 ]; then
    set -- "$@" --force
    container exec "$app_container" "$@"
else
    container exec -it "$app_container" "$@"
fi

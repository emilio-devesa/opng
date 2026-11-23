#!/bin/sh
# Open Pastebin NG - restore a backup into the running container (F3).
#
# Usage:
#   ./deploy/apple-container/restore.sh ./backup.sql [--force]
#   ./deploy/apple-container/restore.sh /backups/opng.sql [--force]
#
# The dump is staged in a private temporary directory on the host. The optional <file>.sha256
# sidecar is copied and verified automatically. Without --force the restore
# tool asks you to type RESTORE. This overwrites the tables in the dump.
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
env_file="${OPNG_ENV_FILE:-$script_dir/opng.env}"
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

# shellcheck disable=SC1090
. "$env_file"
app_container="${OPNG_APP_CONTAINER:-opng-app}"
: "${OPNG_INSTALL_DB_USER:?Set the dedicated migration account for restore}"
: "${OPNG_INSTALL_DB_PASS:?Set the migration password for restore}"
if [ "$OPNG_INSTALL_DB_USER" = "$OPNG_DB_USER" ] || [ "$OPNG_INSTALL_DB_USER" = root ]; then
    echo "ERROR: restoration requires a separate, non-root account." >&2
    exit 1
fi
command -v container >/dev/null 2>&1 || {
    echo "ERROR: the Apple 'container' CLI is not installed." >&2
    exit 1
}
umask 077
stage="$(mktemp -d "${TMPDIR:-/tmp}/opng-restore.XXXXXX")"
trap 'rm -rf "$stage"' EXIT
mkdir "$stage/input"
if [ -f "$file" ]; then
    cp "$file" "$stage/input/dump.sql"
    if [ -f "${file}.sha256" ]; then cp "${file}.sha256" "$stage/input/checksum"; fi
else
    case "$file" in /backups/*) ;; *) echo 'ERROR: expected a local file or /backups/ path.' >&2; exit 1;; esac
    base="${file#/backups/}"
    case "$base" in ''|.|..|*[!A-Za-z0-9._-]*) echo 'ERROR: invalid backup name.' >&2; exit 1;; esac
    container exec "$app_container" cat "$file" > "$stage/input/dump.sql"
    if container exec "$app_container" test -f "${file}.sha256"; then
        container exec "$app_container" cat "${file}.sha256" > "$stage/input/checksum"
    fi
fi
restore_env="$stage/credentials"
{
    printf 'OPNG_DB_HOST=%s\n' "$OPNG_DB_HOST"
    printf 'OPNG_DB_NAME=%s\n' "$OPNG_DB_NAME"
    printf 'OPNG_DB_USER=%s\n' "$OPNG_INSTALL_DB_USER"
    printf 'OPNG_DB_PASS=%s\n' "$OPNG_INSTALL_DB_PASS"
} > "$restore_env"
set -- --network "${OPNG_NETWORK:-opng-net}" --read-only --tmpfs /tmp \
    --mount "type=bind,source=$stage/input,target=/input,readonly" \
    --env-file "$restore_env" --entrypoint php "${OPNG_IMAGE:-opng-app:1.2.0}" tools/restore.php --file=/input/dump.sql
if [ -f "$stage/input/checksum" ]; then
    set -- "$@" --checksum=/input/checksum
fi
if [ "$force" -eq 1 ]; then
    set -- "$@" --force
    container run --rm "$@"
else
    container run --rm -it "$@"
fi

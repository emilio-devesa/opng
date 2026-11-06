#!/bin/sh
# Open Pastebin NG - create a portable backup from the running container (F3).
#
# Usage:
#   ./deploy/apple-container/backup.sh [--name opng-YYYY-MM-DD.sql]
#
# The dump is written to the persistent opng-backups volume at /backups and a
# SHA-256 sidecar is generated next to it. Copy it off the host regularly.
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
app_container="${OPNG_APP_CONTAINER:-opng-app}"
name=""

usage() {
    cat <<'EOT'
Usage: backup.sh [--name FILE.sql]

  --name FILE.sql  Destination name inside /backups (default: timestamped)
EOT
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --name)
            [ "$#" -ge 2 ] || { echo "ERROR: --name needs a value." >&2; exit 2; }
            name="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "ERROR: unknown argument: $1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

[ -n "$name" ] || name="opng-$(date -u +%Y%m%dT%H%M%SZ).sql"
case "$name" in
    *[!A-Za-z0-9._-]*|.*)
        echo "ERROR: --name may only contain letters, digits, dots, underscores and hyphens." >&2
        exit 2
        ;;
esac

command -v container >/dev/null 2>&1 || {
    echo "ERROR: the Apple 'container' CLI is not installed." >&2
    exit 1
}
container exec "$app_container" true >/dev/null 2>&1 || {
    echo "ERROR: container ${app_container} is not running. Start it with start.sh." >&2
    exit 1
}

container exec "$app_container" php tools/backup.php "/backups/$name"
container exec "$app_container" sh -c "cd /backups && sha256sum '$name' > '$name.sha256'"

cat <<EOT
Backup written inside ${app_container}:
  /backups/${name}
  /backups/${name}.sha256

Copy it to the host with:
  container cp ${app_container}:/backups/${name} .
  container cp ${app_container}:/backups/${name}.sha256 .
EOT

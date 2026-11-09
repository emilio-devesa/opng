#!/bin/sh
# Open Pastebin NG - stop Apple Container services (F3).
#
# Usage:
#   ./deploy/apple-container/stop.sh [--keep-db] [--remove]
#
# --keep-db  Stop only the application; leave MariaDB running.
# --remove   Delete the stopped containers (volumes are never deleted).
set -eu

db_container="${OPNG_DB_CONTAINER:-opng-db}"
app_container="${OPNG_APP_CONTAINER:-opng-app}"
keep_db=0
remove=0

usage() {
    cat <<'EOT'
Usage: stop.sh [--keep-db] [--remove]

  --keep-db  Leave the database container running
  --remove   Delete the stopped containers (volumes are preserved)
EOT
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --keep-db)
            keep_db=1
            shift
            ;;
        --remove)
            remove=1
            shift
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

command -v container >/dev/null 2>&1 || {
    echo "ERROR: the Apple 'container' CLI is not installed." >&2
    exit 1
}

stop_container() {
    name="$1"
    if ! container inspect "$name" >/dev/null 2>&1; then
        return 0
    fi
    if container exec "$name" true >/dev/null 2>&1; then
        echo "Stopping ${name}"
        container stop "$name" >/dev/null
    fi
    if [ "$remove" -eq 1 ]; then
        echo "Deleting ${name}"
        container delete "$name" >/dev/null
    fi
}

stop_container "$app_container"
if [ "$keep_db" -eq 0 ]; then
    stop_container "$db_container"
fi

if [ "$remove" -eq 1 ]; then
    echo "Containers removed. Database volume opng-db-data and backups were preserved."
else
    echo "Containers stopped. Start them again with start.sh."
fi

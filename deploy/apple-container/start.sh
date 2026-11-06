#!/bin/sh
# Open Pastebin NG - start Apple Container services (F3).
#
# Usage:
#   ./deploy/apple-container/start.sh [--build] [--env FILE] [--image IMAGE]
#
# Creates the private network and volumes, starts MariaDB, builds the app image
# when missing (or when --build is given), starts the app on 127.0.0.1:8080 and
# waits for /healthz.php.
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "$script_dir/../.." && pwd)"
env_file="$script_dir/opng.env"
image="${OPNG_IMAGE:-opng-app:1.0.0}"
build=0

usage() {
    cat <<'EOT'
Usage: start.sh [--build] [--env FILE] [--image IMAGE]

  --build        Rebuild the application image before starting it
  --env FILE     Environment file (default: deploy/apple-container/opng.env)
  --image IMAGE  Application image name (default: opng-app:1.0.0)

The script never deletes volumes or the database.
EOT
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --build)
            build=1
            shift
            ;;
        --env)
            [ "$#" -ge 2 ] || { echo "ERROR: --env needs a file." >&2; exit 2; }
            env_file="$2"
            shift 2
            ;;
        --image)
            [ "$#" -ge 2 ] || { echo "ERROR: --image needs a value." >&2; exit 2; }
            image="$2"
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

[ -f "$env_file" ] || {
    echo "ERROR: ${env_file} does not exist. Copy opng.env.example and set the secrets." >&2
    exit 1
}

# shellcheck disable=SC1090
set -a
. "$env_file"
set +a

for required in OPNG_DB_NAME OPNG_DB_USER OPNG_DB_PASS OPNG_DB_ROOT_PASSWORD; do
    eval "value=\${$required:-}"
    [ -n "$value" ] || { echo "ERROR: ${required} is empty in ${env_file}." >&2; exit 1; }
done
[ -n "${OPNG_DB_HOST:-}" ] || { echo "ERROR: OPNG_DB_HOST is empty in ${env_file}." >&2; exit 1; }
case "${OPNG_DB_HOST}" in
    opng-db*) ;;
    *) echo "WARNING: OPNG_DB_HOST=${OPNG_DB_HOST}; the app normally reaches MariaDB as opng-db." >&2 ;;
esac

command -v container >/dev/null 2>&1 || {
    echo "ERROR: the Apple 'container' CLI is not installed. See docs/APPLE-CONTAINER.md." >&2
    exit 1
}
container system status >/dev/null 2>&1 || {
    echo "ERROR: Apple Container services are not running. Start them with: container system start" >&2
    exit 1
}

network="${OPNG_NETWORK:-opng-net}"
db_container="${OPNG_DB_CONTAINER:-opng-db}"
app_container="${OPNG_APP_CONTAINER:-opng-app}"
db_volume="${OPNG_DB_VOLUME:-opng-db-data}"
backup_volume="${OPNG_BACKUP_VOLUME:-opng-backups}"
publish="${OPNG_PUBLISH:-127.0.0.1:8080:8080}"

if ! container network ls -q | grep -Fxq "$network"; then
    echo "Creating network ${network}"
    container network create "$network" >/dev/null
fi
if ! container volume ls -q | grep -Fxq "$db_volume"; then
    echo "Creating volume ${db_volume}"
    container volume create -s 20g "$db_volume" >/dev/null
fi
if ! container volume ls -q | grep -Fxq "$backup_volume"; then
    echo "Creating volume ${backup_volume}"
    container volume create -s 20g "$backup_volume" >/dev/null
fi

db_env="$(mktemp "${TMPDIR:-/tmp}/opng-db-env.XXXXXX")"
app_env="$(mktemp "${TMPDIR:-/tmp}/opng-app-env.XXXXXX")"
cleanup() {
    rm -f "$db_env" "$app_env"
}
trap cleanup EXIT HUP INT TERM

{
    printf 'MARIADB_DATABASE=%s\n' "$OPNG_DB_NAME"
    printf 'MARIADB_USER=%s\n' "$OPNG_DB_USER"
    printf 'MARIADB_PASSWORD=%s\n' "$OPNG_DB_PASS"
    printf 'MARIADB_ROOT_PASSWORD=%s\n' "$OPNG_DB_ROOT_PASSWORD"
    printf 'TZ=%s\n' "${TZ:-UTC}"
} > "$db_env"
chmod 600 "$db_env"

# The root password is never handed to the application container.
grep -v '^OPNG_DB_ROOT_PASSWORD=' "$env_file" > "$app_env"
chmod 600 "$app_env"

if container inspect "$db_container" >/dev/null 2>&1; then
    if ! container exec "$db_container" true >/dev/null 2>&1; then
        echo "Starting existing database container ${db_container}"
        container start "$db_container" >/dev/null
    fi
else
    echo "Starting MariaDB container ${db_container}"
    container run -d --name "$db_container" \
        --network "$network" \
        --env-file "$db_env" \
        --volume "${db_volume}:/var/lib/mysql" \
        --cpus 2 --memory 1G \
        mariadb:11 >/dev/null
fi

if [ "$build" -eq 1 ] || ! container image inspect "$image" >/dev/null 2>&1; then
    echo "Building application image ${image}"
    container build -t "$image" -f "$script_dir/Containerfile" "$repo_root"
fi

if container inspect "$app_container" >/dev/null 2>&1; then
    if ! container exec "$app_container" true >/dev/null 2>&1; then
        echo "Starting existing application container ${app_container}"
        container start "$app_container" >/dev/null
    else
        echo "Application container ${app_container} is already running"
    fi
else
    echo "Starting application container ${app_container}"
    container run -d --name "$app_container" \
        --network "$network" \
        --publish "$publish" \
        --env-file "$app_env" \
        --volume "${backup_volume}:/backups" \
        --read-only \
        --tmpfs /tmp \
        --tmpfs /run \
        --tmpfs /var/lib/nginx \
        --tmpfs /var/lib/php/sessions \
        --cpus 2 --memory 1G \
        "$image" >/dev/null
fi

echo "Waiting for the application healthcheck..."
attempt=0
while ! container exec "$app_container" /usr/local/bin/healthcheck.sh >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
        echo "ERROR: the application did not become healthy. Inspect:" >&2
        echo "  container logs $app_container" >&2
        echo "  container logs $db_container" >&2
        exit 1
    fi
    sleep 1
done

cat <<EOT
Open Pastebin NG is running.
  URL:      ${OPNG_PUBLIC_URL:-http://127.0.0.1:8080}
  Health:   container exec $app_container /usr/local/bin/healthcheck.sh
  Logs:     container logs -f $app_container
  Backup:   $script_dir/backup.sh
  Stop:     $script_dir/stop.sh

The database lives in volume ${db_volume}; back it up before upgrades.
EOT

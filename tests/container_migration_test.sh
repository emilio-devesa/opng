#!/bin/sh
# Exercise the host launcher without privileged containers or live credentials.
set -eu
root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
d=$(mktemp -d)
trap 'rm -rf "$d"' EXIT
export FIXTURE_DIR="$d" PATH="$d:$PATH"
cat > "$d/container" <<'SH'
#!/bin/sh
set -eu
case "$1" in
    inspect) exit 1 ;;
    run)
        file=''
        for arg in "$@"; do
            if [ "${previous:-}" = --env-file ]; then file=$arg; fi
            previous=$arg
        done
        case "$*" in
            *src/install.php*)
                grep -qx 'OPNG_INSTALL_DB_USER=migrator' "$file"
                grep -qx 'OPNG_INSTALL_DB_PASS=migration-fixture' "$file"
                ! grep -Eq '^OPNG_(DB_(ROOT_PASSWORD|PASS)|MFA_KEY)=' "$file" || exit 1
                touch "$FIXTURE_DIR/migrated"
                exit "${INSTALL_EXIT:-0}" ;;
            *'--name opng-app '*)
                test -f "$FIXTURE_DIR/migrated"
                ! grep -Eq '(migration-fixture|root-fixture|OPNG_INSTALL_)' "$file" || exit 1
                grep -qx 'OPNG_DB_PASS=runtime-fixture' "$file"
                touch "$FIXTURE_DIR/web" ;;
            *mariadb:11*)
                ! grep -Eq '^MARIADB_(USER|PASSWORD)=' "$file" || exit 1 ;;
        esac ;;
esac
SH
chmod 700 "$d/container"
cat > "$d/env" <<'ENV'
OPNG_DB_NAME=opng
OPNG_DB_HOST=opng-db
OPNG_DB_USER=runtime
OPNG_DB_PASS=runtime-fixture
OPNG_DB_ROOT_PASSWORD=root-fixture
OPNG_INSTALL_DB_USER=migrator
OPNG_INSTALL_DB_PASS=migration-fixture
OPNG_AUTO_MIGRATE=1
ENV
start() { sh "$root/deploy/apple-container/start.sh" --env "$d/env" "$@" >/dev/null; }
start --db-only
test ! -f "$d/migrated" && test ! -f "$d/web"
if (export INSTALL_EXIT=1; start); then exit 1; fi
test ! -f "$d/web"
start
test -f "$d/web"
rm "$d/web" "$d/migrated"
echo 'OPNG_INSTALL_DB_USER=runtime' >> "$d/env"
if start 2>/dev/null; then exit 1; fi
test ! -f "$d/migrated" && test ! -f "$d/web"
echo 'PASS: database-only startup, isolated installer, web environment and migration failure handling'

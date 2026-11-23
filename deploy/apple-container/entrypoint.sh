#!/bin/sh
# Open Pastebin NG - container entrypoint (F3).
#
# Prepares the tmpfs paths required by --read-only, waits for MariaDB, checks runtime grants,
# optionally creates the first
# administrator, then hands control to supervisord.
set -eu

umask 027
cd /var/www/opng

log() {
    printf '[opng-entrypoint] %s\n' "$*" >&2
}

fail() {
    log "ERROR: $*"
    exit 1
}

: "${OPNG_DB_HOST:=opng-db}"
: "${OPNG_DB_NAME:=opng}"
: "${OPNG_DB_USER:=opng}"
: "${OPNG_DB_PASS:=}"

[ -n "$OPNG_DB_PASS" ] || fail "OPNG_DB_PASS is empty; set it from the environment file or secret store."
php -r 'require "src/includes/mfa.php"; exit(mfa_key() === null ? 1 : 0);' \
    || fail "Set OPNG_MFA_KEY to a persistent base64-encoded 32-byte key. For existing MFA accounts use their original key."

# The root filesystem is expected to be read-only. These paths must be tmpfs
# mounts; create them if the container is running with a writable rootfs for
# local debugging, and fail early with an actionable message otherwise.
for directory in /tmp /run /run/nginx /var/lib/nginx /var/lib/php/sessions \
    /tmp/client_temp /tmp/proxy_temp /tmp/fastcgi_temp /tmp/uwsgi_temp /tmp/scgi_temp; do
    mkdir -p "$directory" 2>/dev/null || fail "cannot create ${directory}; mount it as tmpfs or run without --read-only"
done

if [ "$(id -u)" = "0" ]; then
    # Only tmpfs paths are rewritten at runtime; /var/www/opng is already owned
    # by www-data in the image and may be on a read-only root filesystem.
    chown -R www-data:www-data \
        /var/lib/nginx \
        /var/lib/php/sessions \
        /tmp/client_temp /tmp/proxy_temp /tmp/fastcgi_temp /tmp/uwsgi_temp /tmp/scgi_temp \
        /run/nginx
fi

touch /var/lib/php/sessions/.opng-write-check 2>/dev/null \
    || fail "/var/lib/php/sessions is not writable; mount a tmpfs there"
rm -f /var/lib/php/sessions/.opng-write-check

case "$OPNG_DB_HOST" in
    *:*)
        db_host="${OPNG_DB_HOST%:*}"
        db_port="${OPNG_DB_HOST##*:}"
        ;;
    *)
        db_host="$OPNG_DB_HOST"
        db_port="3306"
        ;;
esac

log "waiting for MariaDB at ${db_host}:${db_port}"
attempt=0
while ! MYSQL_PWD="$OPNG_DB_PASS" mariadb-admin ping \
    --host="$db_host" --port="$db_port" --user="$OPNG_DB_USER" \
    --connect-timeout=2 --silent >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
        fail "MariaDB did not become ready after 60 attempts; check 'container logs opng-db'"
    fi
    if [ $((attempt % 10)) -eq 0 ]; then
        log "still waiting for MariaDB (${attempt}/60)"
    fi
    sleep 1
done

php /usr/local/lib/opng/check-runtime.php || fail "Runtime requires only SELECT, INSERT, UPDATE and DELETE on the configured database. Check account provisioning and connectivity."

# Create the first administrator only when all three variables are present.
# create_admin.php is idempotent with --if-empty and does not prompt when the
# password comes from OPNG_ADMIN_PASSWORD.
if [ -n "${OPNG_ADMIN_USERNAME:-}" ] && [ -n "${OPNG_ADMIN_EMAIL:-}" ] && [ -n "${OPNG_ADMIN_PASSWORD:-}" ]; then
    log "ensuring the first administrator exists"
    php tools/create_admin.php \
        --username="$OPNG_ADMIN_USERNAME" \
        --email="$OPNG_ADMIN_EMAIL" \
        --if-empty || fail "the first administrator could not be created"
else
    log "OPNG_ADMIN_USERNAME/OPNG_ADMIN_EMAIL/OPNG_ADMIN_PASSWORD not all set; skipping admin creation"
fi

# Never expose bootstrap credentials to PHP-FPM/worker processes.
unset OPNG_ADMIN_USERNAME OPNG_ADMIN_EMAIL OPNG_ADMIN_PASSWORD 2>/dev/null || true
unset OPNG_INSTALL_DB_HOST OPNG_INSTALL_DB_PORT OPNG_INSTALL_DB_NAME OPNG_INSTALL_DB_USER OPNG_INSTALL_DB_PASS

log "starting supervisord (Nginx on 8080, PHP-FPM on 127.0.0.1:9000)"
exec supervisord -n -c /etc/supervisord.conf

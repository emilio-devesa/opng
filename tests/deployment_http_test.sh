#!/bin/sh
# Opt-in, disposable Apple Container test. Does not expose a host port.
set -eu
[ "${OPNG_TEST_ALLOW_DATABASE_FIXTURES:-}" = 1 ] || { echo 'Enable disposable fixtures first.' >&2; exit 2; }
cd "$(dirname "$0")/.."
umask 077
d=$(mktemp -d)
id="opng-http-$(openssl rand -hex 5)"
db="$id-db"
app="$id-web"
cleanup() {
    for c in "$app" "$db"; do
        container stop "$c" >/dev/null 2>&1 || :
        container delete "$c" >/dev/null 2>&1 || :
    done
    container network delete "$id" >/dev/null 2>&1 || :
    for tag in fixture base; do container image delete "$id:$tag" >/dev/null 2>&1 || :; done
    rm -rf "$d"
}
trap cleanup EXIT
container network create "$id" >/dev/null
pw=$(openssl rand -hex 24)
printf 'MARIADB_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 24)" > "$d/db.env"
container run -d --name "$db" --network "$id" --env-file "$d/db.env" \
    --tmpfs /var/lib/mysql mariadb:11 >/dev/null
wait_for() {
    n=0
    until "$@" >/dev/null 2>&1; do
        n=$((n + 1)); [ "$n" -lt 90 ] || return 1
        sleep 1
    done
}
wait_for container exec "$db" healthcheck.sh --connect --innodb_initialized
{
    printf "CREATE DATABASE opng; USE opng; CREATE USER 'fixture'@'%%' IDENTIFIED BY '%s';\n" "$pw"
    echo "GRANT SELECT, INSERT, UPDATE, DELETE ON opng.* TO 'fixture'@'%';"
    cat database/schema.sql
} | container exec -i "$db" sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot'
container build -t "$id:base" -f deploy/apple-container/Containerfile . >/dev/null
mkdir "$d/build"
printf 'FROM %s:base\nRUN apk add --no-cache apache2 apache2-proxy apache2-ssl openssl python3\nCOPY *.conf *.py *.sh /tmp/\n' \
    "$id" > "$d/build/Containerfile"
cp deploy/apache/opng.conf "$d/build/apache-site.conf"
cp deploy/nginx/opng.conf "$d/build/nginx-site.conf"
cp tests/deployment_http_test.py tests/deployment_http_fixture.sh "$d/build/"
container build -t "$id:fixture" -f "$d/build/Containerfile" "$d/build" >/dev/null
printf 'OPNG_DB_HOST=%s\nOPNG_DB_USER=fixture\nOPNG_DB_NAME=opng\nOPNG_DB_PASS=%s\nOPNG_MFA_KEY=%s\nOPNG_DEV_RESET=1\n' \
    "$db" "$pw" "$(openssl rand -base64 32)" > "$d/web.env"
container run -d --name "$app" --network "$id" --env-file "$d/web.env" "$id:fixture" >/dev/null
wait_for container exec "$app" /usr/local/bin/healthcheck.sh
container exec "$app" sh /tmp/deployment_http_fixture.sh
container exec "$app" env OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 python3 /tmp/deployment_http_test.py exercise /tmp/evidence
container exec "$app" cat /tmp/evidence > "$d/evidence"
container logs "$app" > "$d/container.log" 2>&1
container exec "$app" sh -ec 'cat /var/log/apache2/*.log /var/log/nginx/*.log /tmp/classic-error.log /tmp/contract-fpm.log' > "$d/web.log"
python3 tests/deployment_http_test.py check "$d/evidence" "$d/container.log" "$d/web.log"

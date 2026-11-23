#!/bin/sh
# Fresh local installation of the version advertised on this page.
set -eu
umask 077
mode=${1:-}
case "$mode" in classic|apple) ;; *) echo 'Usage: sh quick-start.sh classic|apple' >&2; exit 2;; esac
mkdir opng-local || { echo 'Use a new directory; opng-local must not exist.' >&2; exit 1; }
cd opng-local
git clone -q --depth 1 --branch v1.2.0 https://github.com/emilio-devesa/opng.git app
runtime=$(openssl rand -hex 24)
migration=$(openssl rand -hex 24)
admin=$(openssl rand -hex 16)
host=127.0.0.1
port=${DB_PORT:-3306}
if [ "$mode" = apple ]; then
    host=opng-db
    port=3306
fi
cat > runtime.env <<EOF
OPNG_DB_HOST=$host:$port
OPNG_DB_NAME=opng
OPNG_DB_USER=opng_web
OPNG_DB_PASS=$runtime
OPNG_MFA_KEY=$(openssl rand -base64 32)
OPNG_HASH_KEY=$(openssl rand -base64 32)
OPNG_PUBLIC_URL=http://127.0.0.1:8080
EOF
printf '%s\n' "$admin" > admin-password
cat > installer.env <<EOF
OPNG_DB_NAME=opng
OPNG_INSTALL_DB_HOST=$host
OPNG_INSTALL_DB_PORT=$port
OPNG_INSTALL_DB_USER=opng_migrator
OPNG_INSTALL_DB_PASS=$migration
EOF
provision() {
    cat <<EOF
CREATE DATABASE opng CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'opng_web'@'$1' IDENTIFIED BY '$runtime';
GRANT SELECT, INSERT, UPDATE, DELETE ON opng.* TO 'opng_web'@'$1';
CREATE USER 'opng_migrator'@'$1' IDENTIFIED BY '$migration';
GRANT SELECT, INSERT, UPDATE, CREATE, ALTER, INDEX, DROP, REFERENCES ON opng.* TO 'opng_migrator'@'$1';
EOF
}
if [ "$mode" = classic ]; then
    # mysql prompts on the terminal; the SQL on stdin contains generated passwords.
    provision 127.0.0.1 | mysql --protocol=TCP -h "$host" -P "$port" -u "${DB_ADMIN:-root}" -p
    cp app/src/config.example.php app/src/config.php
    (set -a; . ./installer.env; php app/src/install.php)
    set -a; . ./runtime.env; set +a
    OPNG_ADMIN_PASSWORD="$admin" php app/tools/create_admin.php --username=admin --email=admin@example.test --if-empty
    echo 'Open http://127.0.0.1:8080; use admin@example.test and the password in opng-local/admin-password.'
    exec php -S 127.0.0.1:8080 -t app/src
fi

container system status >/dev/null
# Create (never reuse) resources. No database port is published.
container network create opng-net
container volume create -s 20g opng-db-data
printf 'MARIADB_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 24)" > db.env
container run -d --name opng-db --network opng-net --env-file db.env \
    --volume opng-db-data:/var/lib/mysql mariadb:11
attempt=0
until container exec opng-db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do
    attempt=$((attempt + 1)); [ "$attempt" -lt 90 ] || { echo 'MariaDB did not become ready.' >&2; exit 1; }
    sleep 1
done
provision '%' | container exec -i opng-db sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot'
container build -t opng-local:1.2.0 -f app/deploy/apple-container/Containerfile app
container run --rm --network opng-net --env-file installer.env \
    --entrypoint php opng-local:1.2.0 src/install.php
# v1.2.0 start.sh expects a root setting even when MariaDB is already running.
# Its app environment excludes this setting; migration settings stay separate.
cp runtime.env opng.env
sed 's/^MARIADB_ROOT_PASSWORD=/OPNG_DB_ROOT_PASSWORD=/' db.env >> opng.env
printf 'OPNG_AUTO_MIGRATE=0\n' >> opng.env
sh app/deploy/apple-container/start.sh --env "$PWD/opng.env" --image opng-local:1.2.0
cp runtime.env admin.env
printf 'OPNG_ADMIN_PASSWORD=%s\n' "$admin" >> admin.env
trap 'rm -f admin.env' EXIT
container run --rm --network opng-net --env-file admin.env --entrypoint php opng-local:1.2.0 \
    tools/create_admin.php --username=admin --email=admin@example.test --if-empty
echo 'Open http://127.0.0.1:8080; use admin@example.test and the password in opng-local/admin-password.'

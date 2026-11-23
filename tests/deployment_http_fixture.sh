#!/bin/sh
# Configures test listeners inside the disposable Alpine app image only.
set -eu
mkdir -p /tmp/test-cert /run/apache2
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost \
    -keyout /tmp/test-cert/privkey.pem -out /tmp/test-cert/fullchain.pem >/dev/null 2>&1
sed -e 's|/etc/letsencrypt/live/paste.example.com|/tmp/test-cert|g' \
    -e 's@proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost@proxy:fcgi://127.0.0.1:9000@g' \
    /tmp/apache-site.conf > /tmp/apache-vhost.conf
sed '/^IncludeOptional .*conf.d/d' /etc/apache2/httpd.conf > /tmp/httpd.conf
for module in ssl socache_shmcb proxy proxy_fcgi rewrite unique_id; do
    echo "LoadModule ${module}_module modules/mod_${module}.so" >> /tmp/httpd.conf
done
printf '%s\n' 'Define APACHE_LOG_DIR /var/log/apache2' 'Listen 443' \
    'ServerName localhost' 'Include /tmp/apache-vhost.conf' >> /tmp/httpd.conf
httpd -t -f /tmp/httpd.conf
httpd -f /tmp/httpd.conf
sed -e 's|/etc/letsencrypt/live/pastebin.example.com|/tmp/test-cert|g' \
    -e 's|unix:/run/php/php8.3-fpm.sock|127.0.0.1:9000|g' \
    -e 's|listen 443 ssl|listen 8443 ssl|' -e 's|listen 80;|listen 8081;|' \
    /tmp/nginx-site.conf >/tmp/nginx-vhost.conf
printf '%s\n' 'pid /tmp/classic.pid; error_log /tmp/classic-error.log; events {}' \
    'http { include /etc/nginx/mime.types; include /tmp/nginx-vhost.conf; }' >/etc/nginx/classic.conf
nginx -t -c /etc/nginx/classic.conf
nginx -c /etc/nginx/classic.conf
# Capture the pool's real access.format even if the process manager discards stdout.
sed -i '/^access.log =/c\access.log = /tmp/contract-fpm.log' /usr/local/etc/php-fpm.d/zz-opng.conf
supervisorctl -c /etc/supervisord.conf restart php-fpm

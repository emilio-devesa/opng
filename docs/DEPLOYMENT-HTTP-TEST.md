# Compare the supplied web-server profiles

With Apple Container running, OpenSSL and Python 3 on PATH, from the repo root:

```sh
OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 sh tests/deployment_http_test.sh
```

The test builds the current application and a disposable Alpine derivative
with Apache, generates a loopback certificate, and provisions a fresh MariaDB
database on a private network. It adapts only the classic templates' socket,
certificate paths and listener ports. No host ports are published. The
fixture, ephemeral database, test images and temporary credentials are
removed on exit.

The same requests go through Apache/TLS, classic Nginx/TLS and container
Nginx to PHP-FPM. The test checks API status, JSON, authentication and cache
headers, query filtering, individual detail and unknown routes. For each
profile it exchanges a synthetic password-reset token for a session, completes
the reset, verifies consumption and checks the database audit events. Finally
it stops **only the fixture's** PHP-FPM and matches each failed response's trace
ID to its access log. It scans access, error, FPM and combined container logs
for the reset markers and bearer token; no real mail or APM service is used.

In a deployment with extra proxies, repeat the reset using a disposable
account and inspect *those systems' own logs* (including error and tracing
exports) for the synthetic token. Do not assume a front proxy inherits the
origin server's policy. For classic PHP-FPM pools with access logging enabled,
use the `access.format` from `deploy/apple-container/php-fpm.conf`; avoid
`%q`, `%Q`, request bodies and raw HTTP headers.

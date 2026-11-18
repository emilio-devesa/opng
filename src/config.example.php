<?php
/**
 * Open Pastebin NG — example configuration.
 *
 * Copy this file to `config.php` and adjust the values:
 *
 *     cp src/config.example.php src/config.php
 *
 * `config.php` is git-ignored on purpose: it holds credentials and must never
 * be committed. It is also blocked from HTTP access by `src/.htaccess`.
 *
 * Runtime values can be overridden with environment variables (useful for
 * Docker/CI). The application account should have DML privileges only:
 *
 *     OPNG_DB_HOST  (default: localhost)
 *     OPNG_DB_USER  (default: opng_runtime)
 *     OPNG_DB_PASS  (default: empty)
 *     OPNG_DB_NAME  (default: opng)
 *
 * Optional runtime limits:
 *
 *     OPNG_FORCE_HTTPS=1          Mark the session cookie Secure when TLS
 *                                 terminates at a proxy that PHP cannot see.
 *     OPNG_PUBLIC_URL             Canonical public URL (for example
 *                                 https://paste.example.com). Used by
 *                                 tools/doctor.php to verify HTTPS.
 *     OPNG_TRUSTED_PROXIES        Comma-separated IPs/CIDRs of reverse proxies
 *                                 (for example 10.0.0.0/8,192.0.2.10). Only
 *                                 their X-Forwarded-For header is trusted for
 *                                 rate limiting; leave empty otherwise.
 *     OPNG_STORAGE_QUOTA_BYTES    Global paste-text quota in bytes
 *                                 (default: 10 GiB).
 *     OPNG_DB_CONNECT_TIMEOUT     MySQL connection timeout in seconds
 *                                 (default: 5; accepted range 1-60).
 *     OPNG_DB_READ_TIMEOUT        Optional MySQL read timeout in seconds
 *                                 (unset by default; range 1-3600). Set it
 *                                 above the slowest expected query.
 *     OPNG_BACKUP_MAX_SECONDS     Maximum duration of an admin backup
 *                                 (default: 300 seconds).
 *     OPNG_BACKUP_MAX_BYTES       Maximum size of an admin backup
 *                                 (default: 512 MiB).
 *     OPNG_HASH_KEY               Secret used to derive HMAC-SHA-256 audit and
 *                                 rate-limit identifiers. Use 32+ raw bytes or
 *                                 a base64 value that decodes to 32+ bytes
 *                                 (for example `openssl rand -base64 32`).
 *                                 Without it the fallback is an unkeyed
 *                                 SHA-256, which the doctor reports as a
 *                                 warning because IPs and emails are
 *                                 low-entropy values.
 *
 * Production credentials must be unique: set a non-empty OPNG_DB_PASS (or
 * $mysql_password) and never reuse the local MAMP/development example values.
 * Rotate the runtime password if it was ever committed or shared.
 *
 * The CLI installer uses a separate, temporary migration account:
 *
 *     OPNG_INSTALL_DB_HOST (defaults to OPNG_DB_HOST)
 *     OPNG_INSTALL_DB_PORT (defaults to mysqli.default_port)
 *     OPNG_INSTALL_DB_USER
 *     OPNG_INSTALL_DB_PASS
 *
 * OPNG_DEV_RESET is intentionally not set here. Setting it to 1 enables a
 * local-only password-reset email simulation; never enable it in production.
 */

$mysql_server   = getenv('OPNG_DB_HOST') ?: 'localhost';
$mysql_username = getenv('OPNG_DB_USER') ?: 'opng_runtime';
$mysql_password = getenv('OPNG_DB_PASS') ?: '';
$mysql_dbname   = getenv('OPNG_DB_NAME') ?: 'opng';

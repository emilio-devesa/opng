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
 * Values can be overridden with environment variables (useful for Docker/CI):
 *
 *     OPNG_DB_HOST  (default: localhost)
 *     OPNG_DB_USER  (default: root)
 *     OPNG_DB_PASS  (default: empty)
 *     OPNG_DB_NAME  (default: opng)
 *
 * OPNG_DEV_RESET is intentionally not set here. Setting it to 1 enables a
 * local-only password-reset email simulation; never enable it in production.
 */

$mysql_server   = getenv('OPNG_DB_HOST') ?: 'localhost';
$mysql_username = getenv('OPNG_DB_USER') ?: 'root';
$mysql_password = getenv('OPNG_DB_PASS') ?: '';
$mysql_dbname   = getenv('OPNG_DB_NAME') ?: 'opng';

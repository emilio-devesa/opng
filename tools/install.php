<?php
/**
 * CLI installer wrapper for classic/shared-hosting releases (F2).
 *
 * Uso (desde la raíz del artefacto):
 *   OPNG_INSTALL_DB_USER=... OPNG_INSTALL_DB_PASS=... php tools/install.php
 *
 * Delegates to src/install.php so a hosted installation never needs to guess
 * the implementation path. Run it with the migration account, then revoke the
 * credentials. The wrapper is CLI-only; the web server denies src/install.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../src/install.php';

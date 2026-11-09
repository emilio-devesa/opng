<?php
/**
 * Apply pending database migrations for Open Pastebin NG (v1.0).
 *
 * Uso (desde la raíz del repo):
 *   php tools/migrate.php
 *
 * - Solo funciona por CLI.
 * - Usa las credenciales de src/config.php.
 * - Aplica database/migrations/NNNN_*.sql en orden y registra cada versión en
 *   schema_migrations.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/includes/schema.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
    $db->set_charset('utf8mb4');

    $applied = schema_apply_migrations($db, __DIR__ . '/../database/migrations');
    if ($applied === []) {
        echo "Schema is up to date.\n";
    } else {
        foreach ($applied as $version) {
            echo "Applied {$version}\n";
        }
    }

    $current = schema_current_version($db);
    echo 'Current schema version: ' . ($current ?? 'none') . "\n";
    $db->close();
    exit(0);
} catch (mysqli_sql_exception | RuntimeException $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
}

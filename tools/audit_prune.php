<?php
/**
 * Prune old audit-log rows for Open Pastebin NG (SEC-14).
 *
 * Usage (from the repository root):
 *   php tools/audit_prune.php [days]
 *
 * - CLI only.
 * - Without an argument it uses OPNG_AUDIT_RETENTION_DAYS (90 by default).
 * - The admin panel also removes old rows opportunistically (1 in 100 events),
 *   so this command is optional.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/includes/audit_log.php';

$days = null;
if (isset($argv[1])) {
    $days = filter_var($argv[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($days === false) {
        fwrite(STDERR, "ERROR: days must be a positive integer\n");
        exit(1);
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
    $db->set_charset('utf8mb4');
    $deleted = audit_log_prune($db, $days);
    $db->close();
    echo "Deleted {$deleted} audit-log row(s).\n";
    exit(0);
} catch (mysqli_sql_exception $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
}

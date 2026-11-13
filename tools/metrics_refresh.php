<?php
/**
 * Refresh the admin dashboard cache ahead of its TTL (PERF-17).
 *
 * Usage (from the repository root):
 *   php tools/metrics_refresh.php
 *
 * - CLI only.
 * - Computes the dashboard once and stores it in `admin_metrics_cache`, so the
 *   next administrator visit does not pay the refresh in the request.
 * - Run it from cron, for example every five minutes (or slightly below
 *   OPNG_ADMIN_METRICS_TTL). The dashboard still falls back to computing the
 *   metrics when the cache is missing or expired.
 * - The totals come from the storage counters; run `tools/recount_storage.php
 *   --check` separately if you also want to verify counter integrity.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/includes/admin_metrics.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
    $db->set_charset('utf8mb4');

    $metrics = admin_metrics_compute($db);
    admin_metrics_write_cache($db, $metrics);
    $db->close();

    echo 'Admin metrics refreshed: ' . $metrics['total_entries'] . ' entries, '
        . $metrics['total_users'] . ' users, ' . $metrics['paste_bytes'] . " paste bytes.\n";
    exit(0);
} catch (Throwable $exception) {
    if (isset($db) && $db instanceof mysqli) {
        $db->close();
    }
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
}

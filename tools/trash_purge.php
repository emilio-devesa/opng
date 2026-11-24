<?php
/**
 * Purge trashed threads past their retention window (10.3).
 *
 * Usage (from the repository root):
 *   php tools/trash_purge.php [days]
 *
 * - CLI only.
 * - Without an argument it uses OPNG_TRASH_RETENTION_DAYS (7 by default).
 * - Removes expired threads in bounded batches. Each thread is one transaction
 *   that follows the global lock order storage_counters -> users -> entries and
 *   updates the counters exactly. A run stops after a thread/time budget, so
 *   cron or a systemd timer resumes the backlog on the next invocation.
 * - Intended for cron or a systemd timer; the admin panel exposes a manual run.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/includes/trash.php';
require __DIR__ . '/../src/includes/audit_log.php';

$days = null;
if (isset($argv[1])) {
    $days = filter_var($argv[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
    if ($days === false) {
        fwrite(STDERR, "ERROR: days must be an integer between 1 and 365\n");
        exit(1);
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
    $db->set_charset('utf8mb4');
    $result = trash_purge_expired($db, $days);
    audit_log_record($db, null, 'trash.purge_expired', 'entries:' . $result['purged']);
    $db->close();
    echo "Purged {$result['purged']} entr(ies) from {$result['threads']} thread(s) and released {$result['bytes']} bytes.\n";
    if ($result['remaining'] > 0) {
        echo "{$result['remaining']} expired thread(s) remain; run the command again or wait for the next schedule.\n";
    }
    exit(0);
} catch (mysqli_sql_exception | RuntimeException $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
}

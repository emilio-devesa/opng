<?php
/**
 * Create a portable SQL backup for Open Pastebin NG (ROB-07).
 *
 * Usage (from the repository root):
 *   php tools/backup.php [output.sql]
 *   php tools/backup.php - > backup.sql
 *
 * - CLI only.
 * - Uses the credentials from src/config.php (read-only/DML account).
 * - The dump contains no CREATE DATABASE or USE: the destination is chosen
 *   when importing, for example `mysql opng_restore < backup.sql`.
 * - Honors OPNG_BACKUP_MAX_SECONDS and OPNG_BACKUP_MAX_BYTES.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/includes/sql_backup.php';

$output = $argv[1] ?? '-';
if ($output === '--help' || $output === '-h') {
    echo "Usage: php tools/backup.php [output.sql|-]\n";
    exit(0);
}
if (count($argv) > 2) {
    fwrite(STDERR, "Usage: php tools/backup.php [output.sql|-]\n");
    exit(2);
}

$to_stdout = $output === '-';
$stream = $to_stdout ? fopen('php://stdout', 'wb') : fopen($output, 'wb');
if ($stream === false) {
    fwrite(STDERR, "ERROR: could not open the backup destination.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
    $db->set_charset('utf8mb4');

    $database_name = sql_backup_dump(
        $db,
        $stream,
        sql_backup_max_seconds(),
        sql_backup_max_bytes()
    );

    $db->close();
    fflush($stream);
    fclose($stream);

    if (!$to_stdout) {
        fwrite(STDERR, "Backup of {$database_name} written to {$output}.\n");
    }
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    if (isset($db) && $db instanceof mysqli) {
        $db->close();
    }
    if (is_resource($stream)) {
        fclose($stream);
    }
    if (!$to_stdout && is_file($output)) {
        @unlink($output);
    }
    exit(1);
}

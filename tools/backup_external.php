<?php
/**
 * Run or verify an off-host backup for Open Pastebin NG (10.7).
 *
 * Usage (from the repository root):
 *   php tools/backup_external.php
 *   php tools/backup_external.php --verify=/absolute/path/to/backup.sql
 *
 * - CLI only.
 * - Configuration is read from OPNG_BACKUP_* environment variables.
 * - The command exits non-zero when the target is disabled or the run fails,
 *   so cron/monitoring can alert on it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/includes/external_backup.php';
require __DIR__ . '/../src/includes/audit_log.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// PHP 8.5 may report register_argc_argv=Off while the CLI still defines
// $argv; $_SERVER['argv'] is available in every CLI invocation.
$argv = $_SERVER['argv'] ?? [];

$verifyPath = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--verify=')) {
        $verifyPath = substr($argument, strlen('--verify='));
    }
}

if (!external_backup_enabled()) {
    fwrite(STDERR, "ERROR: set OPNG_BACKUP_TARGET to local or s3 first.\n");
    exit(1);
}
$configErrors = external_backup_validate_config();
if ($configErrors !== []) {
    fwrite(STDERR, 'ERROR: ' . implode(' ', $configErrors) . "\n");
    exit(1);
}

try {
    if ($verifyPath !== null) {
        $result = external_backup_verify_file($verifyPath);
        echo "Verified backup: {$result['tables']} table(s), {$result['rows']} row(s)"
            . ($result['encrypted'] ? ' (encrypted)' : '') . ".\n";
    } else {
        $db = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
        $db->set_charset('utf8mb4');
        $result = external_backup_run($db);
        audit_log_record($db, null, 'backup.external_cli', $result['name']);
        echo "Backup written: {$result['name']} ({$result['bytes']} bytes, sha256 {$result['checksum']}).\n";
        $db->close();
    }
    exit(0);
} catch (mysqli_sql_exception | RuntimeException $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
}

<?php
require_once('../includes/session.php');
session_start_secured();
require_once('../includes/csrf.php');
require_once('../includes/http_input.php');
require_once('../includes/admin_guard.php');
require_once('../includes/reauth.php');
require_once('../includes/sql_backup.php');
require_once('../includes/audit_log.php');
require_once('../database.php');
require_once("../includes/error_messages.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    opng_error_redirect('error_csrf', '../error.php');
}

$db = database_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable', '../error.php');
}

$session_user_id = require_admin($db, 'backup.php');

// A valid CSRF token is not proof that the account owner approved downloading
// password hashes and reset tokens (SEC-07).
$current_password = http_string_parameter($_POST, 'current_password', 72, false);
if (!reauthenticate_user($db, $session_user_id, $current_password, 'admin_reauth_backup')) {
    $db->close();
    opng_error_redirect('error_reauth', '../error.php');
}

// Bound the dump so a large database cannot exhaust the request time or the
// temporary stream. Operators can raise both limits for scheduled backups.
$backup_max_seconds = sql_backup_max_seconds();
$backup_max_bytes = sql_backup_max_bytes();
if (function_exists('set_time_limit')) {
    @set_time_limit($backup_max_seconds + 30);
}

$temporary_stream = fopen('php://temp/maxmemory:2097152', 'w+b');
if ($temporary_stream === false) {
    $db->close();
    throw new RuntimeException('Backup temporary stream is unavailable.');
}

try {
    $database_name = sql_backup_dump($db, $temporary_stream, $backup_max_seconds, $backup_max_bytes);
    audit_log_record($db, $session_user_id, 'backup.download', $database_name);
    $db->close();
} catch (mysqli_sql_exception | RuntimeException $exception) {
    $backup_limit_exceeded = $exception instanceof SqlBackupLimitException;
    error_log('OPNG backup.php: ' . $exception->getMessage());
    try {
        $db->rollback();
    } catch (mysqli_sql_exception $rollback_exception) {
        error_log('OPNG backup.php: rollback failed: ' . $rollback_exception->getMessage());
    }
    $db->close();
    fclose($temporary_stream);
    opng_error_redirect(
        $backup_limit_exceeded ? 'error_backup_limit' : 'error_database_operation',
        '../error.php'
    );
}

if (fseek($temporary_stream, 0) !== 0) {
    // No response body has been sent yet, so fail cleanly instead of throwing
    // an uncaught exception that would leave a truncated download (ROB-05).
    error_log('OPNG backup.php: backup temporary stream could not be rewound.');
    fclose($temporary_stream);
    opng_error_redirect('error_database_operation', '../error.php');
}

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="opng-database-backup-' . gmdate('Ymd-His') . '.sql"');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
fpassthru($temporary_stream);
fclose($temporary_stream);

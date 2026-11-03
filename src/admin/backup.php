<?php
require_once('../includes/session.php');
session_start_secured();
require_once('../includes/csrf.php');
require_once('../includes/http_input.php');
require_once('../includes/admin_guard.php');
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

// Bound the dump so a large database cannot exhaust the request time or the
// temporary stream. Operators can raise both limits for scheduled backups.
$backup_max_seconds = filter_var(getenv('OPNG_BACKUP_MAX_SECONDS'), FILTER_VALIDATE_INT);
if ($backup_max_seconds === false || $backup_max_seconds < 1) {
    $backup_max_seconds = 300;
}
$backup_max_bytes = filter_var(getenv('OPNG_BACKUP_MAX_BYTES'), FILTER_VALIDATE_INT);
if ($backup_max_bytes === false || $backup_max_bytes < 1) {
    $backup_max_bytes = 536870912;
}
$backup_started_at = time();
$backup_limit_exceeded = false;
if (function_exists('set_time_limit')) {
    @set_time_limit($backup_max_seconds + 30);
}

$temporary_stream = fopen('php://temp/maxmemory:2097152', 'w+b');
if ($temporary_stream === false) {
    $db->close();
    throw new RuntimeException('Backup temporary stream is unavailable.');
}

try {
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);

    $database_result = $db->query('SELECT DATABASE() AS database_name');
    $database_row = $database_result->fetch_assoc();
    $database_result->free();
    $database_name = is_array($database_row) && is_string($database_row['database_name'] ?? null)
        ? $database_row['database_name']
        : '';
    if ($database_name === '') {
        throw new RuntimeException('Current database name is unavailable.');
    }

    $tables_result = $db->query(
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY TABLE_NAME"
    );
    $tables = [];
    while ($table_row = $tables_result->fetch_assoc()) {
        if (!is_string($table_row['TABLE_NAME'] ?? null)) {
            throw new RuntimeException('Invalid table name in database metadata.');
        }
        $tables[] = $table_row['TABLE_NAME'];
    }
    $tables_result->free();
    if ($tables === []) {
        throw new RuntimeException('No database tables are available for backup.');
    }

    $write = static function ($stream, string $contents) use (
        $backup_max_seconds,
        $backup_max_bytes,
        $backup_started_at,
        &$backup_limit_exceeded
    ): void {
        if (time() - $backup_started_at > $backup_max_seconds) {
            $backup_limit_exceeded = true;
            throw new RuntimeException('Backup exceeded OPNG_BACKUP_MAX_SECONDS.');
        }

        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Backup could not be written to its temporary stream.');
            }
            $offset += $written;
        }

        $size = ftell($stream);
        if ($size === false || $size > $backup_max_bytes) {
            $backup_limit_exceeded = true;
            throw new RuntimeException('Backup exceeded OPNG_BACKUP_MAX_BYTES.');
        }
    };
    $quoted_database = '`' . str_replace('`', '``', $database_name) . '`';
    $write(
        $temporary_stream,
        "-- Open Pastebin NG SQL backup\n"
        . '-- Created: ' . gmdate('Y-m-d H:i:s') . " UTC\n"
        . "-- Contains database structure and rows, including password hashes and reset tokens.\n"
        . "-- Store this file securely. Importing it drops and recreates the listed tables.\n\n"
        . "CREATE DATABASE IF NOT EXISTS {$quoted_database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
        . "USE {$quoted_database};\n"
        . "SET NAMES utf8mb4;\n"
        . "SET FOREIGN_KEY_CHECKS=0;\n\n"
    );

    foreach ($tables as $table_name) {
        $quoted_table = '`' . str_replace('`', '``', $table_name) . '`';
        $schema_result = $db->query('SHOW CREATE TABLE ' . $quoted_table);
        $schema_row = $schema_result->fetch_assoc();
        $schema_result->free();
        $create_statement = is_array($schema_row) && is_string($schema_row['Create Table'] ?? null)
            ? $schema_row['Create Table']
            : null;
        if ($create_statement === null) {
            throw new RuntimeException('Table schema is unavailable for backup.');
        }

        $write($temporary_stream, "DROP TABLE IF EXISTS {$quoted_table};\n{$create_statement};\n\n");
        $result = $db->query('SELECT * FROM ' . $quoted_table, MYSQLI_USE_RESULT);
        $fields = $result->fetch_fields();
        $quoted_columns = array_map(
            static fn ($field): string => '`' . str_replace('`', '``', $field->name) . '`',
            $fields
        );
        $column_list = implode(', ', $quoted_columns);

        while ($row = $result->fetch_assoc()) {
            $values = [];
            foreach ($fields as $field) {
                $values[] = sql_backup_value($row[$field->name] ?? null, $field->type);
            }
            $write(
                $temporary_stream,
                'INSERT INTO ' . $quoted_table . ' (' . $column_list . ') VALUES ('
                . implode(', ', $values) . ");\n"
            );
        }
        $result->free();
        $write($temporary_stream, "\n");
    }

    $write($temporary_stream, "SET FOREIGN_KEY_CHECKS=1;\n");
    $db->commit();
    audit_log_record($db, $session_user_id, 'backup.download', $database_name);
    $db->close();
} catch (mysqli_sql_exception | RuntimeException $exception) {
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

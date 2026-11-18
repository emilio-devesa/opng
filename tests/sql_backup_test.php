<?php

require __DIR__ . '/../src/includes/sql_backup.php';

$root = dirname(__DIR__);
$failures = [];

function assert_backup_value(string $expected, string $actual, string $message, array &$failures): void
{
    if ($expected !== $actual) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

function assert_backup_true(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

assert_backup_value(
    'NULL',
    sql_backup_value(null, MYSQLI_TYPE_VAR_STRING),
    'SQL backup preserves NULL values',
    $failures
);
assert_backup_value(
    '42',
    sql_backup_value('42', MYSQLI_TYPE_LONG),
    'SQL backup emits numeric values as numeric literals',
    $failures
);
assert_backup_value(
    'CONVERT(0x' . bin2hex("text ' ; DROP TABLE users -- ñ") . ' USING utf8mb4)',
    sql_backup_value("text ' ; DROP TABLE users -- ñ", MYSQLI_TYPE_VAR_STRING),
    'SQL backup encodes text as a UTF-8 hex expression',
    $failures
);
assert_backup_value(
    '0x00ff',
    sql_backup_value("\x00\xff", MYSQLI_TYPE_BLOB),
    'SQL backup preserves binary data as a hex literal',
    $failures
);

$invalid_numeric_rejected = false;
try {
    sql_backup_value('1 OR 1=1', MYSQLI_TYPE_LONG);
} catch (UnexpectedValueException) {
    $invalid_numeric_rejected = true;
}
assert_backup_true(
    $invalid_numeric_rejected,
    'SQL backup rejects invalid numeric values',
    $failures
);

assert_backup_true(
    function_exists('sql_backup_dump')
        && function_exists('sql_backup_max_seconds')
        && function_exists('sql_backup_max_bytes')
        && class_exists('SqlBackupLimitException'),
    'the shared dump helpers and limit exception are available',
    $failures
);

$library = file_get_contents($root . '/src/includes/sql_backup.php');
assert_backup_true(
    is_string($library)
        && str_contains($library, 'MYSQLI_TRANS_START_READ_ONLY')
        && str_contains($library, 'SET FOREIGN_KEY_CHECKS=0;')
        && str_contains($library, 'SET FOREIGN_KEY_CHECKS=1;')
        && !str_contains($library, 'CREATE DATABASE IF NOT EXISTS')
        && !str_contains($library, 'USE {$'),
    'the dump uses a read-only snapshot, manages foreign keys and stays database-portable',
    $failures
);

// SEC-15: password-reset tokens are bearer credentials and must never survive
// in a downloadable dump.
assert_backup_true(
    is_string($library)
        && str_contains($library, "\$redacted_columns = ['reset_token' => true, 'reset_token_expires' => true];")
        && str_contains($library, 'isset($redacted_columns[$column_name])')
        && str_contains($library, 'Password-reset tokens are exported as NULL')
        && str_contains($library, 'must be reissued'),
    'the dump redacts password-reset tokens and documents that they must be reissued',
    $failures
);

assert_backup_true(
    defined('OPNG_BACKUP_BATCH_ROWS')
        && defined('OPNG_BACKUP_BATCH_BYTES')
        && OPNG_BACKUP_BATCH_ROWS > 1
        && OPNG_BACKUP_BATCH_BYTES > 0,
    'the dump batches have bounded row and byte limits',
    $failures
);

assert_backup_true(
    is_string($library)
        && str_contains($library, 'count($batch_rows) >= OPNG_BACKUP_BATCH_ROWS')
        && str_contains($library, '$batch_bytes >= OPNG_BACKUP_BATCH_BYTES')
        && str_contains($library, 'implode(",\n", $batch_rows)')
        && substr_count($library, " VALUES\\n") > 0,
    'rows are written as multi-row VALUES statements bounded by rows and bytes',
    $failures
);

assert_backup_true(
    is_string($library)
        && str_contains($library, 'catch (Throwable $exception)')
        && str_contains($library, '$db->rollback();')
        && str_contains($library, 'throw $exception;'),
    'a failed dump rolls the read-only snapshot back and rethrows',
    $failures
);

$tool = file_get_contents($root . '/tools/backup.php');
assert_backup_true(
    is_string($tool)
        && str_contains($tool, "PHP_SAPI !== 'cli'")
        && str_contains($tool, 'sql_backup_dump(')
        && str_contains($tool, 'sql_backup_max_bytes()')
        && str_contains($tool, 'unlink($output)'),
    'tools/backup.php is CLI-only, reuses the shared dump and removes partial files',
    $failures
);

$readme = file_get_contents($root . '/README.md');
assert_backup_true(
    is_string($readme)
        && str_contains($readme, 'php tools/backup.php backup.sql')
        && str_contains($readme, 'mysql opng_restore < backup.sql'),
    'README.md documents portable restoration and scheduled backups',
    $failures
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "SQL backup contracts passed: safe value encoding, portable dumps and the CLI wrapper.\n";

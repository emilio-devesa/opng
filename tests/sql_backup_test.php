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

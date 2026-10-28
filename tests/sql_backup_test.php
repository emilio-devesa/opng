<?php

require __DIR__ . '/../src/includes/sql_backup.php';

function assert_backup_value(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

assert_backup_value(
    'NULL',
    sql_backup_value(null, MYSQLI_TYPE_VAR_STRING),
    'SQL backup preserves NULL values'
);
assert_backup_value(
    '42',
    sql_backup_value('42', MYSQLI_TYPE_LONG),
    'SQL backup emits numeric values as numeric literals'
);
assert_backup_value(
    'CONVERT(0x' . bin2hex("text ' ; DROP TABLE users -- ñ") . ' USING utf8mb4)',
    sql_backup_value("text ' ; DROP TABLE users -- ñ", MYSQLI_TYPE_VAR_STRING),
    'SQL backup encodes text as a UTF-8 hex expression'
);
assert_backup_value(
    '0x00ff',
    sql_backup_value("\x00\xff", MYSQLI_TYPE_BLOB),
    'SQL backup preserves binary data as a hex literal'
);

try {
    sql_backup_value('1 OR 1=1', MYSQLI_TYPE_LONG);
} catch (UnexpectedValueException) {
    echo "PASS: SQL backup rejects invalid numeric values\n";
    exit(0);
}

fwrite(STDERR, "FAIL: SQL backup accepted an invalid numeric value\n");
exit(1);

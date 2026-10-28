<?php

/**
 * Encode a database value as a safe MySQL expression for a SQL backup.
 */
function sql_backup_value(mixed $value, int $type): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new UnexpectedValueException('Unsupported database value in backup.');
    }
    $value = (string) $value;

    $numeric_types = [
        MYSQLI_TYPE_TINY,
        MYSQLI_TYPE_SHORT,
        MYSQLI_TYPE_LONG,
        MYSQLI_TYPE_LONGLONG,
        MYSQLI_TYPE_INT24,
        MYSQLI_TYPE_DECIMAL,
        MYSQLI_TYPE_NEWDECIMAL,
        MYSQLI_TYPE_FLOAT,
        MYSQLI_TYPE_DOUBLE,
        MYSQLI_TYPE_YEAR,
    ];
    if (in_array($type, $numeric_types, true)) {
        if (preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?\z/', $value) !== 1) {
            throw new UnexpectedValueException('Invalid numeric database value in backup.');
        }

        return $value;
    }

    $binary_types = [
        MYSQLI_TYPE_TINY_BLOB,
        MYSQLI_TYPE_MEDIUM_BLOB,
        MYSQLI_TYPE_LONG_BLOB,
        MYSQLI_TYPE_BLOB,
        MYSQLI_TYPE_BIT,
        MYSQLI_TYPE_GEOMETRY,
    ];
    $hex = '0x' . bin2hex($value);
    if (in_array($type, $binary_types, true)) {
        return $hex;
    }

    return 'CONVERT(' . $hex . ' USING utf8mb4)';
}

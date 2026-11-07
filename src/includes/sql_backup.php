<?php
declare(strict_types=1);

/**
 * Raised when a dump would exceed the configured time or byte limit.
 */
class SqlBackupLimitException extends RuntimeException
{
}

/**
 * Read the backup time limit from the environment.
 */
function sql_backup_max_seconds(): int
{
    $value = filter_var(getenv('OPNG_BACKUP_MAX_SECONDS'), FILTER_VALIDATE_INT);
    if ($value === false || $value < 1) {
        return 300;
    }

    return $value;
}

/**
 * Read the backup byte limit from the environment.
 */
function sql_backup_max_bytes(): int
{
    $value = filter_var(getenv('OPNG_BACKUP_MAX_BYTES'), FILTER_VALIDATE_INT);
    if ($value === false || $value < 1) {
        return 536870912;
    }

    return $value;
}

/**
 * Write a portable SQL dump of every base table in the current database.
 *
 * The dump deliberately has no CREATE DATABASE or USE statement: the operator
 * chooses the target schema when importing, for example
 * `mysql opng_restore < backup.sql`. Importing it drops and recreates the
 * listed tables, so always restore into a disposable database first.
 *
 * @param resource $stream writable stream (php://temp, file or php://stdout)
 * @return string source database name
 */
function sql_backup_dump(
    mysqli $db,
    $stream,
    int $max_seconds = 300,
    int $max_bytes = 536870912
): string {
    if (!is_resource($stream)) {
        throw new InvalidArgumentException('Backup destination is not a writable stream.');
    }

    $started_at = time();
    $write = static function ($stream, string $contents) use (
        $max_seconds,
        $max_bytes,
        $started_at
    ): void {
        if (time() - $started_at > $max_seconds) {
            throw new SqlBackupLimitException('Backup exceeded OPNG_BACKUP_MAX_SECONDS.');
        }

        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Backup could not be written to its destination stream.');
            }
            $offset += $written;
        }

        $size = ftell($stream);
        if ($size === false || $size > $max_bytes) {
            throw new SqlBackupLimitException('Backup exceeded OPNG_BACKUP_MAX_BYTES.');
        }
    };

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

    $safe_database_name = str_replace(["\r", "\n"], ' ', $database_name);
    $write(
        $stream,
        "-- Open Pastebin NG SQL backup\n"
        . '-- Source database: ' . $safe_database_name . "\n"
        . '-- Created: ' . gmdate('Y-m-d H:i:s') . " UTC\n"
        . "-- Contains database structure and rows, including password hashes and reset tokens.\n"
        . "-- Store this file securely. Import it into a chosen database, for example:\n"
        . "--   mysql <target-database> < backup.sql\n"
        . "-- Importing it drops and recreates the listed tables.\n\n"
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

        $write($stream, "DROP TABLE IF EXISTS {$quoted_table};\n{$create_statement};\n\n");
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
                $stream,
                'INSERT INTO ' . $quoted_table . ' (' . $column_list . ') VALUES ('
                . implode(', ', $values) . ");\n"
            );
        }
        $result->free();
        $write($stream, "\n");
    }

    $write($stream, "SET FOREIGN_KEY_CHECKS=1;\n");
    $db->commit();

    return $database_name;
}

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

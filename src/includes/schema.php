<?php

declare(strict_types=1);

// includes/schema.php
// Versioned schema migrations (ROB-01).
//
// database/schema.sql documents the canonical end state; every change after the
// v1.0 baseline lives in database/migrations/NNNN_*.sql. Both src/install.php
// and tools/migrate.php apply pending files in filename order and record them
// in schema_migrations, so an instance can always report its schema version.

const OPNG_SCHEMA_MIGRATIONS_TABLE = 'schema_migrations';

function schema_migrations_table(mysqli $db): void
{
    $table = OPNG_SCHEMA_MIGRATIONS_TABLE;
    $db->query(
        "CREATE TABLE IF NOT EXISTS `{$table}` (
            version VARCHAR(50) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * @return list<string>
 */
function schema_applied_versions(mysqli $db): array
{
    schema_migrations_table($db);
    $result = $db->query('SELECT version FROM ' . OPNG_SCHEMA_MIGRATIONS_TABLE . ' ORDER BY version ASC');
    $versions = [];
    while ($row = $result->fetch_assoc()) {
        if (is_string($row['version'] ?? null)) {
            $versions[] = $row['version'];
        }
    }
    $result->free();

    return $versions;
}

/**
 * List valid migration files, sorted by version.
 *
 * @return array<string, string> version => absolute path
 */
function schema_migration_files(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = glob(rtrim($directory, '/\\') . '/*.sql');
    if ($files === false) {
        return [];
    }

    $migrations = [];
    foreach ($files as $file) {
        $version = basename($file, '.sql');
        if (preg_match('/\A\d{4}_[a-z0-9_]+\z/', $version) === 1) {
            $migrations[$version] = $file;
        }
    }
    ksort($migrations, SORT_STRING);

    return $migrations;
}

/**
 * Split a migration file into individual statements.
 *
 * The scanner respects single-quoted strings (including backslash escapes and
 * doubled quotes), double-quoted strings, backtick identifiers, `--`/`#` line
 * comments and block comments. A semicolon inside any of those contexts no
 * longer splits the statement (ROB-18). Comments are removed before execution.
 *
 * MySQL's `DELIMITER` client command is not used by the shipped migrations; a
 * migration that needs stored procedures with `;` in their body should be
 * applied with the native client instead.
 *
 * @return list<string>
 */
function schema_split_sql(string $sql): array
{
    $statements = [];
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('SQL stream is unavailable.');
    }
    try {
        $offset = 0;
        $length = strlen($sql);
        while ($offset < $length) {
            $written = fwrite($stream, substr($sql, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('SQL stream could not be written.');
            }
            $offset += $written;
        }
        if (fseek($stream, 0) !== 0) {
            throw new RuntimeException('SQL stream is not seekable.');
        }
        schema_split_sql_stream($stream, static function (string $statement) use (&$statements): void {
            $statements[] = $statement;
        });
    } finally {
        fclose($stream);
    }

    return $statements;
}

/**
 * Split SQL statements from a stream with bounded memory.
 *
 * @param resource $stream
 * @param callable(string): void $emit
 */
function schema_split_sql_stream($stream, callable $emit): void
{
    if (!is_resource($stream)) {
        throw new InvalidArgumentException('SQL source is not a readable stream.');
    }
    $buffer = '';
    $position = 0;
    $eof = false;
    $fill = static function (int $length) use (&$buffer, &$position, &$eof, $stream): void {
        while (strlen($buffer) - $position < $length && !$eof) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                throw new RuntimeException('SQL stream could not be read.');
            }
            if ($chunk === '') {
                if (feof($stream)) {
                    $eof = true;
                    break;
                }
                continue;
            }
            // Drop the bytes already consumed before growing the buffer. This
            // runs once per 64 KiB read, not once per character.
            $buffer = substr($buffer, $position);
            $position = 0;
            $buffer .= $chunk;
        }
    };
    $peek = static function (int $offset) use (&$buffer, &$position, $fill): ?string {
        $fill($offset + 1);

        return $buffer[$position + $offset] ?? null;
    };
    $next = static function () use (&$buffer, &$position, $fill): ?string {
        $fill(1);
        if (!isset($buffer[$position])) {
            return null;
        }
        $char = $buffer[$position];
        $position++;

        return $char;
    };

    $current = '';
    $single = false;
    $double = false;
    $backtick = false;
    $line_comment = false;
    $block_comment = false;

    while (($char = $next()) !== null) {
        $following = $peek(0) ?? '';
        $after_next = $peek(1) ?? '';

        if ($line_comment) {
            if ($char === "\n") {
                $line_comment = false;
                $current .= "\n";
            }
            continue;
        }

        if ($block_comment) {
            if ($char === '*' && $following === '/') {
                $block_comment = false;
                $next();
            }
            continue;
        }

        if ($single) {
            $current .= $char;
            if ($char === '\\' && $following !== '') {
                $current .= $next();
                continue;
            }
            if ($char === "'") {
                if ($following === "'") {
                    $current .= $next();
                    continue;
                }
                $single = false;
            }
            continue;
        }

        if ($double) {
            $current .= $char;
            if ($char === '\\' && $following !== '') {
                $current .= $next();
                continue;
            }
            if ($char === '"') {
                if ($following === '"') {
                    $current .= $next();
                    continue;
                }
                $double = false;
            }
            continue;
        }

        if ($backtick) {
            $current .= $char;
            if ($char === '`') {
                if ($following === '`') {
                    $current .= $next();
                    continue;
                }
                $backtick = false;
            }
            continue;
        }

        if ($char === '-' && $following === '-' && ($after_next === '' || ctype_space($after_next))) {
            $line_comment = true;
            $next();
            continue;
        }
        if ($char === '#') {
            $line_comment = true;
            continue;
        }
        if ($char === '/' && $following === '*') {
            $block_comment = true;
            $next();
            continue;
        }
        if ($char === "'") {
            $single = true;
            $current .= $char;
            continue;
        }
        if ($char === '"') {
            $double = true;
            $current .= $char;
            continue;
        }
        if ($char === '`') {
            $backtick = true;
            $current .= $char;
            continue;
        }
        if ($char === ';') {
            $statement = trim($current);
            if ($statement !== '') {
                $emit($statement);
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $statement = trim($current);
    if ($statement !== '') {
        $emit($statement);
    }
}

/**
 * Apply every pending migration in order.
 *
 * MySQL DDL commits implicitly, so a failed migration is reported and left
 * unrecorded; rerunning the command resumes from that version.
 *
 * @return list<string> versions applied by this call
 */
function schema_apply_migrations(mysqli $db, string $directory): array
{
    schema_migrations_table($db);
    $applied = schema_applied_versions($db);
    $applied_lookup = array_fill_keys($applied, true);
    $newly_applied = [];

    foreach (schema_migration_files($directory) as $version => $path) {
        if (isset($applied_lookup[$version])) {
            continue;
        }

        $sql = file_get_contents($path);
        if (!is_string($sql)) {
            throw new RuntimeException("Migration {$version} could not be read.");
        }

        foreach (schema_split_sql($sql) as $statement) {
            $result = $db->query($statement);
            if ($result === false) {
                throw new RuntimeException(
                    "Migration {$version} statement failed: " . $db->error
                );
            }
            if ($result instanceof mysqli_result) {
                $result->free();
            }
        }

        $stmt = $db->prepare(
            'INSERT INTO ' . OPNG_SCHEMA_MIGRATIONS_TABLE . ' (version) VALUES (?)'
        );
        if (!$stmt) {
            throw new RuntimeException("Migration {$version} could not be recorded.");
        }
        $stmt->bind_param('s', $version);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException("Migration {$version} could not be recorded.");
        }
        $stmt->close();

        $newly_applied[] = $version;
        $applied_lookup[$version] = true;
    }

    return $newly_applied;
}

function schema_current_version(mysqli $db): ?string
{
    $versions = schema_applied_versions($db);

    return $versions === [] ? null : $versions[count($versions) - 1];
}

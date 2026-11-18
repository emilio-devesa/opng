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
    $current = '';
    $length = strlen($sql);
    $single = false;
    $double = false;
    $backtick = false;
    $line_comment = false;
    $block_comment = false;

    for ($index = 0; $index < $length; $index++) {
        $char = $sql[$index];
        $next = $index + 1 < $length ? $sql[$index + 1] : '';
        $after_next = $index + 2 < $length ? $sql[$index + 2] : '';

        if ($line_comment) {
            if ($char === "\n") {
                $line_comment = false;
                $current .= "\n";
            }
            continue;
        }

        if ($block_comment) {
            if ($char === '*' && $next === '/') {
                $block_comment = false;
                $index++;
            }
            continue;
        }

        if ($single) {
            $current .= $char;
            if ($char === '\\' && $next !== '') {
                $current .= $next;
                $index++;
                continue;
            }
            if ($char === "'") {
                if ($next === "'") {
                    $current .= $next;
                    $index++;
                    continue;
                }
                $single = false;
            }
            continue;
        }

        if ($double) {
            $current .= $char;
            if ($char === '\\' && $next !== '') {
                $current .= $next;
                $index++;
                continue;
            }
            if ($char === '"') {
                if ($next === '"') {
                    $current .= $next;
                    $index++;
                    continue;
                }
                $double = false;
            }
            continue;
        }

        if ($backtick) {
            $current .= $char;
            if ($char === '`') {
                if ($next === '`') {
                    $current .= $next;
                    $index++;
                    continue;
                }
                $backtick = false;
            }
            continue;
        }

        if ($char === '-' && $next === '-' && ($after_next === '' || ctype_space($after_next))) {
            $line_comment = true;
            $index++;
            continue;
        }
        if ($char === '#') {
            $line_comment = true;
            continue;
        }
        if ($char === '/' && $next === '*') {
            $block_comment = true;
            $index++;
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
                $statements[] = $statement;
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $statement = trim($current);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
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

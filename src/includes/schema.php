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
 * Migrations in this repository are plain DDL/DML without semicolons inside
 * string literals; comments are stripped before splitting.
 *
 * @return list<string>
 */
function schema_split_sql(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? '';
    $statements = [];
    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $statements[] = $statement;
        }
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
            $db->query($statement);
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

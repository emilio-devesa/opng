<?php

require __DIR__ . '/../src/includes/schema.php';

$root = dirname(__DIR__);
$failures = [];

function expect_migration(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

$migrations = schema_migration_files($root . '/database/migrations');
expect_migration(
    array_key_exists('0001_baseline', $migrations),
    'the v1.0 baseline migration is present and recognized',
    $failures
);
expect_migration(
    array_key_exists('0002_foreign_keys', $migrations),
    'the foreign-key migration is present and recognized',
    $failures
);
expect_migration(
    array_key_exists('0003_admin_metrics_expires_bigint', $migrations),
    'the admin metrics timestamp migration is present and recognized',
    $failures
);
expect_migration(
    array_key_exists('0004_entry_threads', $migrations),
    'the entry-thread migration is present and recognized',
    $failures
);
$threads_migration = file_get_contents($migrations['0004_entry_threads'] ?? '');
expect_migration(
    is_string($threads_migration)
        && str_contains($threads_migration, 'ADD COLUMN parent_id VARCHAR(255) NULL')
        && str_contains($threads_migration, 'ADD COLUMN root_id VARCHAR(255) NULL')
        && str_contains($threads_migration, 'ADD COLUMN depth TINYINT UNSIGNED NOT NULL DEFAULT 0')
        && str_contains($threads_migration, 'idx_entries_root')
        && str_contains($threads_migration, 'ON DELETE SET NULL')
        && str_contains($threads_migration, 'UPDATE entries SET root_id = id, depth = 0'),
    'the entry-thread migration adds the relation, indexes, delete rule and backfill',
    $failures
);
$expires_migration = file_get_contents($migrations['0003_admin_metrics_expires_bigint'] ?? '');
expect_migration(
    is_string($expires_migration)
        && str_contains($expires_migration, 'ALTER TABLE admin_metrics_cache')
        && str_contains($expires_migration, 'MODIFY expires_at BIGINT UNSIGNED NOT NULL'),
    'the admin metrics timestamp migration widens expires_at to BIGINT',
    $failures
);
$foreign_keys = file_get_contents($migrations['0002_foreign_keys'] ?? '');
expect_migration(
    is_string($foreign_keys)
        && str_contains($foreign_keys, 'SET user_id = NULL')
        && str_contains($foreign_keys, 'SET actor_id = NULL')
        && str_contains($foreign_keys, "CONSTRAINT_NAME = 'fk_entries_user'")
        && str_contains($foreign_keys, "CONSTRAINT_NAME = 'fk_audit_actor'")
        && str_contains($foreign_keys, 'ON DELETE SET NULL'),
    'the foreign-key migration detaches orphan rows and is re-runnable',
    $failures
);
$versions = array_keys($migrations);
$sorted = $versions;
sort($sorted, SORT_STRING);
expect_migration($versions === $sorted, 'migrations are sorted by numeric version', $failures);

$baseline = file_get_contents($migrations['0001_baseline'] ?? '');
expect_migration(
    is_string($baseline) && schema_split_sql($baseline) === [],
    'the baseline marker contains no executable statements',
    $failures
);

$split = schema_split_sql(
    "-- comment; with semicolon\nCREATE TABLE a (id INT);\n\n/* block; comment */\nINSERT INTO a VALUES (1);\n"
);
expect_migration(
    $split === ['CREATE TABLE a (id INT)', 'INSERT INTO a VALUES (1)'],
    'SQL files are split into executable statements and comments are removed',
    $failures
);

$schema = file_get_contents($root . '/database/schema.sql');
foreach ([
    'schema_migrations',
    'users',
    'entries',
    'rate_limits',
    'admin_metrics_cache',
    'storage_counters',
    'audit_log',
    'idx_users_created',
    'idx_users_role',
    'idx_entries_cursor',
    'idx_entries_parent',
    'idx_entries_root',
    'ft_entries_topic',
    'fk_entries_user',
    'fk_entries_parent',
    'fk_audit_actor',
] as $marker) {
    expect_migration(
        is_string($schema) && str_contains($schema, $marker),
        "database/schema.sql documents {$marker}",
        $failures
    );
}

$install = file_get_contents($root . '/src/install.php');
expect_migration(
    is_string($install)
        && str_contains($install, "require __DIR__ . '/includes/schema.php'")
        && str_contains($install, 'schema_apply_migrations($conn'),
    'the installer applies and records pending migrations',
    $failures
);

$migrate = file_get_contents($root . '/tools/migrate.php');
expect_migration(
    is_string($migrate)
        && str_contains($migrate, "PHP_SAPI !== 'cli'")
        && str_contains($migrate, 'schema_apply_migrations($db')
        && str_contains($migrate, 'schema_current_version($db)'),
    'tools/migrate.php is CLI-only and reports the current schema version',
    $failures
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Schema migration contracts passed: baseline, ordered SQL files, canonical schema, installer hook, and CLI command.\n";

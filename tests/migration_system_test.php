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
expect_migration(
    array_key_exists('0005_storage_counters', $migrations),
    'the storage-counter migration is present and recognized',
    $failures
);
expect_migration(
    array_key_exists('0006_entry_author_label', $migrations),
    'the entry author-label migration is present and recognized',
    $failures
);
expect_migration(
    array_key_exists('0007_admin_mfa', $migrations),
    'the administrator MFA migration is present and recognized',
    $failures
);
expect_migration(
    array_key_exists('0008_entry_retention', $migrations),
    'the entry retention migration is present and recognized',
    $failures
);
expect_migration(
    array_key_exists('0009_api_tokens', $migrations),
    'the API token migration is present and recognized',
    $failures
);
$mfa_migration = file_get_contents($migrations['0007_admin_mfa'] ?? '');
expect_migration(
    is_string($mfa_migration)
        && str_contains($mfa_migration, 'ADD COLUMN mfa_secret VARBINARY(255) NULL')
        && str_contains($mfa_migration, 'ADD COLUMN mfa_enabled TINYINT UNSIGNED NOT NULL DEFAULT 0')
        && str_contains($mfa_migration, 'ADD COLUMN mfa_enrolled_at DATETIME NULL')
        && str_contains($mfa_migration, 'CREATE TABLE IF NOT EXISTS mfa_recovery_codes')
        && str_contains($mfa_migration, 'information_schema.COLUMNS'),
    'the MFA migration adds the secret columns and recovery codes idempotently',
    $failures
);
$retention_migration = file_get_contents($migrations['0008_entry_retention'] ?? '');
expect_migration(
    is_string($retention_migration)
        && str_contains($retention_migration, 'ADD COLUMN deleted_at DATETIME NULL')
        && str_contains($retention_migration, 'ADD COLUMN expires_at DATETIME NULL')
        && str_contains($retention_migration, "ADD COLUMN visibility ENUM('public', 'unlisted', 'private') NOT NULL DEFAULT 'public'")
        && str_contains($retention_migration, 'ADD COLUMN protection_hash VARCHAR(255) NULL')
        && str_contains($retention_migration, 'idx_entries_visibility')
        && str_contains($retention_migration, 'idx_entries_expires')
        && str_contains($retention_migration, 'idx_entries_deleted')
        && str_contains($retention_migration, 'information_schema.COLUMNS')
        && str_contains($retention_migration, 'information_schema.STATISTICS'),
    'the retention migration adds trash, expiry, visibility and protection idempotently',
    $failures
);
$tokens_migration = file_get_contents($migrations['0009_api_tokens'] ?? '');
expect_migration(
    is_string($tokens_migration)
        && str_contains($tokens_migration, 'CREATE TABLE IF NOT EXISTS api_tokens')
        && str_contains($tokens_migration, 'UNIQUE KEY uniq_api_token_hash')
        && str_contains($tokens_migration, 'ON DELETE CASCADE'),
    'the API token migration creates the hashed-token table idempotently',
    $failures
);
$storage_counters_migration = file_get_contents($migrations['0005_storage_counters'] ?? '');
expect_migration(
    is_string($storage_counters_migration)
        && str_contains($storage_counters_migration, 'users ADD COLUMN used_bytes BIGINT UNSIGNED')
        && str_contains($storage_counters_migration, 'storage_counters ADD COLUMN used_entries BIGINT UNSIGNED')
        && str_contains($storage_counters_migration, 'information_schema.COLUMNS')
        && str_contains($storage_counters_migration, 'SET u.used_bytes = COALESCE(entry_usage.used_bytes, 0)')
        && str_contains($storage_counters_migration, 'SET used_entries = (SELECT COUNT(*) FROM entries)'),
    'the storage-counter migration adds both counters, backfills them and is re-runnable',
    $failures
);
$author_label_migration = file_get_contents($migrations['0006_entry_author_label'] ?? '');
expect_migration(
    is_string($author_label_migration)
        && str_contains($author_label_migration, "ADD COLUMN author_label VARCHAR(50) NOT NULL DEFAULT 'Guest'")
        && str_contains($author_label_migration, 'idx_entries_author')
        && str_contains($author_label_migration, 'information_schema.COLUMNS')
        && str_contains($author_label_migration, 'information_schema.STATISTICS')
        && str_contains($author_label_migration, "SET e.author_label = COALESCE(u.username, 'Guest')"),
    'the author-label migration adds the column, index, backfill and idempotence checks',
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
        && str_contains($threads_migration, 'UPDATE entries SET root_id = id, depth = 0')
        && str_contains($threads_migration, 'information_schema.COLUMNS')
        && str_contains($threads_migration, 'information_schema.STATISTICS')
        && str_contains($threads_migration, 'information_schema.TABLE_CONSTRAINTS'),
    'the entry-thread migration adds the relation, indexes, delete rule, backfill and idempotence checks',
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

$split_literal = schema_split_sql(
    "INSERT INTO a VALUES ('semi;colon', 'it''s; fine');\nSELECT 1;\n"
);
expect_migration(
    $split_literal === [
        "INSERT INTO a VALUES ('semi;colon', 'it''s; fine')",
        'SELECT 1',
    ],
    'semicolons inside string literals do not split a statement',
    $failures
);

$split_quoted = schema_split_sql(
    "CREATE TABLE `weird;name` (`col;umn` INT);\nINSERT INTO `weird;name` VALUES (1);\n"
);
expect_migration(
    $split_quoted === [
        'CREATE TABLE `weird;name` (`col;umn` INT)',
        'INSERT INTO `weird;name` VALUES (1)',
    ],
    'semicolons inside backtick identifiers do not split a statement',
    $failures
);

$split_escaped = schema_split_sql(
    "INSERT INTO a VALUES ('backslash \\\\; escaped', \"double \\\"; quoted\");\n"
);
expect_migration(
    $split_escaped === [
        "INSERT INTO a VALUES ('backslash \\\\; escaped', \"double \\\"; quoted\")",
    ],
    'escaped quotes do not expose a semicolon to the statement splitter',
    $failures
);

$split_comments = schema_split_sql(
    "SELECT 1; -- trailing; comment\n# hash; comment\nSELECT 2 /* block; comment */;\n"
);
expect_migration(
    $split_comments === ['SELECT 1', 'SELECT 2'],
    'line and block comments are removed without breaking statements',
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
    'used_entries',
    'audit_log',
    'used_bytes',
    'author_label',
    'idx_users_created',
    'idx_users_role',
    'idx_entries_cursor',
    'idx_entries_parent',
    'idx_entries_root',
    'idx_entries_author',
    'ft_entries_topic',
    '0005_storage_counters',
    '0006_entry_author_label',
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

<?php

if (getenv('OPNG_TEST_ALLOW_DATABASE_FIXTURES') !== '1') {
    throw new RuntimeException('Run this integration test only against an isolated disposable database.');
}

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/includes/pagination_cursor.php';
require __DIR__ . '/../src/includes/user_quota.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
$db->set_charset('utf8mb4');

$settings = $db->query('SELECT @@lower_case_table_names AS case_mode')->fetch_assoc();
if ((int) $settings['case_mode'] !== 0) {
    throw new RuntimeException('This integration test requires case-sensitive table names.');
}

$result = $db->query(
    "SELECT TABLE_NAME
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
);
$table_names = [];
while ($row = $result->fetch_assoc()) {
    $table_names[] = $row['TABLE_NAME'];
}
$result->free();
sort($table_names);
if ($table_names !== ['admin_metrics_cache', 'audit_log', 'entries', 'rate_limits', 'schema_migrations', 'storage_counters', 'users']) {
    throw new RuntimeException('The installer did not leave exactly the lowercase application tables.');
}
echo "PASS: application tables use lowercase names on a case-sensitive server\n";

$migration_versions = [];
$migration_result = $db->query('SELECT version FROM schema_migrations ORDER BY version');
while ($migration_row = $migration_result->fetch_assoc()) {
    $migration_versions[] = $migration_row['version'];
}
$migration_result->free();
foreach (['0001_baseline', '0002_foreign_keys', '0003_admin_metrics_expires_bigint', '0004_entry_threads'] as $expected_migration) {
    if (!in_array($expected_migration, $migration_versions, true)) {
        throw new RuntimeException("The installer did not record migration {$expected_migration}.");
    }
}
echo "PASS: schema_migrations records the v1.0 baseline and every upgrade migration\n";

$fk_rules = [];
$fk_result = $db->query(
    "SELECT TABLE_NAME, CONSTRAINT_NAME, DELETE_RULE
     FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND CONSTRAINT_NAME IN ('fk_entries_user', 'fk_audit_actor')"
);
while ($fk_row = $fk_result->fetch_assoc()) {
    $fk_rules[$fk_row['CONSTRAINT_NAME']] = [
        'table' => $fk_row['TABLE_NAME'],
        'delete_rule' => $fk_row['DELETE_RULE'],
    ];
}
$fk_result->free();
if (
    ($fk_rules['fk_entries_user']['table'] ?? null) !== 'entries'
    || ($fk_rules['fk_entries_user']['delete_rule'] ?? null) !== 'SET NULL'
    || ($fk_rules['fk_audit_actor']['table'] ?? null) !== 'audit_log'
    || ($fk_rules['fk_audit_actor']['delete_rule'] ?? null) !== 'SET NULL'
) {
    throw new RuntimeException('The user/actor foreign keys were not created with ON DELETE SET NULL.');
}
echo "PASS: entries.user_id and audit_log.actor_id use ON DELETE SET NULL\n";

$audit_columns = [];
$audit_result = $db->query(
    "SELECT COLUMN_NAME
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'audit_log'"
);
while ($audit_row = $audit_result->fetch_assoc()) {
    $audit_columns[] = $audit_row['COLUMN_NAME'];
}
$audit_result->free();
sort($audit_columns);
if ($audit_columns !== ['action', 'actor_id', 'created_at', 'id', 'ip_hash', 'target']) {
    throw new RuntimeException('The audit_log table does not expose the expected columns.');
}
echo "PASS: audit_log table has the expected columns\n";

$user_columns = [];
$user_result = $db->query(
    "SELECT COLUMN_NAME
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'users'"
);
while ($user_row = $user_result->fetch_assoc()) {
    $user_columns[] = strtolower($user_row['COLUMN_NAME']);
}
$user_result->free();
sort($user_columns);
$expected_user_columns = [
    'created_at',
    'email',
    'id',
    'password_hash',
    'reset_token',
    'reset_token_expires',
    'role',
    'session_version',
    'username',
];
if ($user_columns !== $expected_user_columns) {
    throw new RuntimeException('The installer did not add the legacy users role/reset columns.');
}
echo "PASS: legacy users columns were added idempotently\n";

$unique_columns = [];
$index_result = $db->query(
    "SELECT INDEX_NAME, COLUMN_NAME
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'users'
       AND NON_UNIQUE = 0
     ORDER BY INDEX_NAME, SEQ_IN_INDEX"
);
$index_columns = [];
while ($index_row = $index_result->fetch_assoc()) {
    $index_columns[$index_row['INDEX_NAME']][] = strtolower($index_row['COLUMN_NAME']);
}
$index_result->free();
foreach ($index_columns as $columns) {
    if (count($columns) === 1) {
        $unique_columns[] = $columns[0];
    }
}
if (!in_array('username', $unique_columns, true) || !in_array('email', $unique_columns, true)) {
    throw new RuntimeException('The users unique username/email indexes are missing.');
}
echo "PASS: users unique username/email indexes are present\n";

$user_index_names = [];
$user_index_result = $db->query(
    "SELECT DISTINCT INDEX_NAME
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'users'"
);
while ($user_index_row = $user_index_result->fetch_assoc()) {
    $user_index_names[] = $user_index_row['INDEX_NAME'];
}
$user_index_result->free();
foreach (['idx_users_created', 'idx_users_role'] as $required_user_index) {
    if (!in_array($required_user_index, $user_index_names, true)) {
        throw new RuntimeException("The users index {$required_user_index} is missing.");
    }
}
echo "PASS: users creation-date and role indexes are present\n";

$entries_meta = $db->query(
    "SELECT ENGINE, TABLE_COLLATION
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'entries'"
)->fetch_assoc();
if (
    $entries_meta === null
    || $entries_meta['ENGINE'] !== 'InnoDB'
    || $entries_meta['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci'
) {
    throw new RuntimeException('The installer did not convert entries to InnoDB/utf8mb4_unicode_ci.');
}
echo "PASS: entries uses InnoDB and utf8mb4_unicode_ci\n";

$entry = $db->query("SELECT id, text FROM entries WHERE id = 'legacy'")->fetch_assoc();
if ($entry === null || $entry['text'] !== 'legacy text') {
    throw new RuntimeException('Legacy entry data was not preserved during table migration.');
}

$column = $db->query(
    "SELECT DATA_TYPE
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'entries'
       AND COLUMN_NAME = 'text'"
)->fetch_assoc();
if ($column === null || $column['DATA_TYPE'] !== 'mediumtext') {
    throw new RuntimeException('The entries.text column was not widened to MEDIUMTEXT.');
}

$date_column = $db->query(
    "SELECT DATA_TYPE, IS_NULLABLE
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'entries'
       AND COLUMN_NAME = 'date'"
)->fetch_assoc();
if (
    $date_column === null
    || $date_column['DATA_TYPE'] !== 'datetime'
    || $date_column['IS_NULLABLE'] !== 'NO'
) {
    throw new RuntimeException('entries.date was not migrated to NOT NULL DATETIME.');
}

$topic_column = $db->query(
    "SELECT DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'entries'
       AND COLUMN_NAME = 'topic'"
)->fetch_assoc();
if (
    $topic_column === null
    || $topic_column['DATA_TYPE'] !== 'varchar'
    || (int) $topic_column['CHARACTER_MAXIMUM_LENGTH'] !== 255
    || $topic_column['IS_NULLABLE'] !== 'NO'
) {
    throw new RuntimeException('entries.topic was not migrated to VARCHAR(255) NOT NULL.');
}

$language_column = $db->query(
    "SELECT DATA_TYPE, IS_NULLABLE
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'entries'
       AND COLUMN_NAME = 'language'"
)->fetch_assoc();
if (
    $language_column === null
    || $language_column['DATA_TYPE'] !== 'varchar'
    || $language_column['IS_NULLABLE'] !== 'NO'
) {
    throw new RuntimeException('entries.language was not migrated to NOT NULL.');
}

$index = $db->query(
    "SELECT INDEX_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'entries'
       AND INDEX_NAME = 'idx_entries_date'"
)->fetch_assoc();
if ($index === null) {
    throw new RuntimeException('The entries date index is missing.');
}

foreach (['idx_entries_cursor', 'idx_entries_user', 'idx_entries_topic', 'idx_entries_language', 'ft_entries_topic'] as $required_index) {
    $stmt = $db->prepare(
        "SELECT INDEX_TYPE FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'entries'
           AND INDEX_NAME = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $required_index);
    $stmt->execute();
    $index = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($index === null) {
        throw new RuntimeException("The entries index {$required_index} is missing.");
    }
}

$thread_columns = [];
$thread_column_result = $db->query(
    "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'entries'
       AND COLUMN_NAME IN ('parent_id', 'root_id', 'depth')"
);
while ($thread_column_row = $thread_column_result->fetch_assoc()) {
    $thread_columns[$thread_column_row['COLUMN_NAME']] = $thread_column_row;
}
$thread_column_result->free();
if (
    !isset($thread_columns['parent_id'], $thread_columns['root_id'], $thread_columns['depth'])
    || $thread_columns['parent_id']['DATA_TYPE'] !== 'varchar'
    || $thread_columns['parent_id']['IS_NULLABLE'] !== 'YES'
    || $thread_columns['root_id']['DATA_TYPE'] !== 'varchar'
    || $thread_columns['root_id']['IS_NULLABLE'] !== 'YES'
    || $thread_columns['depth']['DATA_TYPE'] !== 'tinyint'
    || $thread_columns['depth']['IS_NULLABLE'] !== 'NO'
) {
    throw new RuntimeException('Migration 0004 did not add the thread columns to an upgraded entries table.');
}
echo "PASS: migration 0004 added parent_id, root_id and depth to the upgraded table\n";

foreach (['idx_entries_parent', 'idx_entries_root'] as $thread_index) {
    $thread_index_result = $db->query(
        "SELECT INDEX_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'entries'
           AND INDEX_NAME = '{$thread_index}'
         LIMIT 1"
    )->fetch_assoc();
    if ($thread_index_result === null) {
        throw new RuntimeException("The thread index {$thread_index} is missing.");
    }
}
echo "PASS: thread parent/root indexes are present\n";

$thread_fk = $db->query(
    "SELECT DELETE_RULE
     FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND CONSTRAINT_NAME = 'fk_entries_parent'"
)->fetch_assoc();
if ($thread_fk === null || $thread_fk['DELETE_RULE'] !== 'SET NULL') {
    throw new RuntimeException('fk_entries_parent was not created with ON DELETE SET NULL.');
}
echo "PASS: fk_entries_parent preserves replies with ON DELETE SET NULL\n";

$legacy_thread = $db->query(
    "SELECT root_id, depth FROM entries WHERE id = 'legacy'"
)->fetch_assoc();
if ($legacy_thread === null || $legacy_thread['root_id'] !== 'legacy' || (int) $legacy_thread['depth'] !== 0) {
    throw new RuntimeException('Migration 0004 did not backfill the legacy entry as a thread root.');
}
echo "PASS: existing entries were backfilled with root_id = id and depth = 0\n";

$search = '+legacy*';
$legacy_id = 'legacy';
$stmt = $db->prepare(
    "SELECT id FROM entries
     WHERE MATCH(topic) AGAINST (? IN BOOLEAN MODE) AND id = ?"
);
$stmt->bind_param('ss', $search, $legacy_id);
$stmt->execute();
$search_result = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($search_result === null) {
    throw new RuntimeException('The topic full-text index did not return the legacy row.');
}
echo "PASS: legacy rows, MEDIUMTEXT, and listing/search/quota indexes are preserved/installed\n";
echo "PASS: full-text topic search returns indexed results\n";

$stmt = $db->prepare(
    "INSERT INTO entries (id, user_id, language, text, topic) VALUES ('runtime-dml-probe', NULL, 'text', 'probe', 'probe')"
);
$stmt->execute();
$stmt->close();
$db->query("DELETE FROM entries WHERE id = 'runtime-dml-probe'");
echo "PASS: runtime credentials can perform application DML\n";

$large_text = str_repeat('x', 1048576);
$stmt = $db->prepare(
    "INSERT INTO entries (id, user_id, language, text, topic) VALUES ('runtime-large-probe', NULL, 'text', ?, 'probe')"
);
$stmt->bind_param('s', $large_text);
$stmt->execute();
$stmt->close();
$large_size = $db->query(
    "SELECT OCTET_LENGTH(text) AS size FROM entries WHERE id = 'runtime-large-probe'"
)->fetch_assoc();
if ($large_size === null || (int) $large_size['size'] !== 1048576) {
    throw new RuntimeException('The runtime account could not persist a 1 MiB paste.');
}
$db->query("DELETE FROM entries WHERE id = 'runtime-large-probe'");
echo "PASS: runtime credentials can store a 1 MiB paste\n";

$legacy_user = $db->query("SELECT id FROM users WHERE username = 'legacy'")->fetch_assoc();
if ($legacy_user === null) {
    throw new RuntimeException('The legacy account required for the quota test is missing.');
}
$legacy_user_id = (int) $legacy_user['id'];
$quota_probe = 'runtime-quota-probe';
$stmt = $db->prepare(
    "INSERT INTO entries (id, user_id, language, text, topic) VALUES (?, ?, 'text', 'quota', 'quota')"
);
$stmt->bind_param('si', $quota_probe, $legacy_user_id);
$stmt->execute();
$stmt->close();
$db->begin_transaction();
$usage = lock_user_quota($db, $legacy_user_id);
if ($usage === null || $usage['used_bytes'] < 5 || !user_quota_allows($usage, 5, 5)) {
    throw new RuntimeException('The account quota helper did not include stored paste bytes.');
}
$db->commit();
$db->query("DELETE FROM entries WHERE id = 'runtime-quota-probe'");
echo "PASS: runtime credentials can lock accounts and calculate quota usage\n";

$cursor_entries = [
    ['runtime-cursor-probe-a', '2001-01-01 00:00:00'],
    ['runtime-cursor-probe-b', '2002-01-01 00:00:00'],
    ['runtime-cursor-probe-c', '2003-01-01 00:00:00'],
];
$stmt = $db->prepare(
    "INSERT INTO entries (id, user_id, language, text, topic, date) VALUES (?, NULL, 'text', 'cursor', 'cursor', ?)"
);
foreach ($cursor_entries as [$cursor_id, $cursor_date]) {
    $stmt->bind_param('ss', $cursor_id, $cursor_date);
    $stmt->execute();
}
$stmt->close();

$first_page = $db->query(
    "SELECT id, date FROM entries
     WHERE id LIKE 'runtime-cursor-probe-%'
     ORDER BY date DESC, id DESC
     LIMIT 2"
);
$first_rows = $first_page->fetch_all(MYSQLI_ASSOC);
$first_page->free();
if (count($first_rows) !== 2 || $first_rows[0]['id'] !== 'runtime-cursor-probe-c') {
    throw new RuntimeException('The first cursor page was not ordered by newest date first.');
}
$older_cursor = ['id' => $first_rows[1]['id'], 'date' => $first_rows[1]['date']];
$older_condition = entry_cursor_condition($older_cursor, 'older');
$stmt = $db->prepare(
    "SELECT id, date FROM entries
     WHERE id LIKE 'runtime-cursor-probe-%' AND {$older_condition['sql']}
     ORDER BY date DESC, id DESC
     LIMIT 2"
);
$stmt->execute($older_condition['params']);
$older_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
if (count($older_rows) !== 1 || $older_rows[0]['id'] !== 'runtime-cursor-probe-a') {
    throw new RuntimeException('The older cursor page did not return the next stable entry.');
}
$newer_condition = entry_cursor_condition(
    ['id' => $older_rows[0]['id'], 'date' => $older_rows[0]['date']],
    'newer'
);
$stmt = $db->prepare(
    "SELECT id, date FROM entries
     WHERE id LIKE 'runtime-cursor-probe-%' AND {$newer_condition['sql']}
     ORDER BY date ASC, id ASC
     LIMIT 2"
);
$stmt->execute($newer_condition['params']);
$newer_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
if (
    count($newer_rows) !== 2
    || $newer_rows[0]['id'] !== 'runtime-cursor-probe-b'
    || $newer_rows[1]['id'] !== 'runtime-cursor-probe-c'
) {
    throw new RuntimeException('The newer cursor page did not return the previous entries.');
}
$db->query("DELETE FROM entries WHERE id LIKE 'runtime-cursor-probe-%'");
echo "PASS: cursor pagination navigates both directions with stable ordering\n";

try {
    $db->query('CREATE TABLE runtime_privilege_probe (id INT)');
    throw new RuntimeException('The runtime database account unexpectedly has DDL privileges.');
} catch (mysqli_sql_exception) {
    echo "PASS: runtime credentials cannot create tables\n";
}

$db->close();

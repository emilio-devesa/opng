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
if ($table_names !== ['entries', 'rate_limits', 'users']) {
    throw new RuntimeException('The installer did not leave exactly the lowercase application tables.');
}
echo "PASS: application tables use lowercase names on a case-sensitive server\n";

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

$index = $db->query(
    "SELECT INDEX_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'entries'
       AND INDEX_NAME = 'idx_entries_date'"
)->fetch_assoc();
if ($index === null) {
    throw new RuntimeException('The entries date index is missing.');
}

foreach (['idx_entries_cursor', 'idx_entries_user', 'ft_entries_topic'] as $required_index) {
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

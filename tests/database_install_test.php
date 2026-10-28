<?php

require __DIR__ . '/../src/config.php';

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
echo "PASS: legacy rows, MEDIUMTEXT, and the date index are preserved/installed\n";

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

try {
    $db->query('CREATE TABLE runtime_privilege_probe (id INT)');
    throw new RuntimeException('The runtime database account unexpectedly has DDL privileges.');
} catch (mysqli_sql_exception) {
    echo "PASS: runtime credentials cannot create tables\n";
}

$db->close();

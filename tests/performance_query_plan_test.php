<?php

if (getenv('OPNG_TEST_ALLOW_PERFORMANCE_FIXTURES') !== '1') {
    throw new RuntimeException('Run this query-plan test only against an isolated disposable database.');
}

require __DIR__ . '/../src/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
$db->set_charset('utf8mb4');

$owner = $db->query("SELECT id FROM users WHERE role = 'user' ORDER BY id LIMIT 1")->fetch_assoc();
if ($owner === null) {
    throw new RuntimeException('The isolated performance database needs a normal user.');
}
$owner_id = (int) $owner['id'];
$prefix = 'runtime-perf-' . bin2hex(random_bytes(6)) . '-';
$stmt = $db->prepare(
    'INSERT INTO entries (id, user_id, language, text, topic, date) VALUES (?, ?, ?, ?, ?, ?)'
);
$transaction_started = false;

try {
    $db->begin_transaction();
    $transaction_started = true;
    $base_timestamp = 1577836800;
    for ($index = 0; $index < 5000; $index++) {
        $id = $prefix . str_pad((string) $index, 5, '0', STR_PAD_LEFT);
        $language = 'text';
        $text = 'Synthetic performance-plan fixture.';
        $topic = $index % 2 === 0 ? 'needle performance match' : 'other performance topic';
        $date = gmdate('Y-m-d H:i:s', $base_timestamp + $index);
        $stmt->bind_param('sissss', $id, $owner_id, $language, $text, $topic, $date);
        $stmt->execute();
    }
    if (!$db->commit()) {
        throw new RuntimeException('Synthetic performance fixtures could not be committed.');
    }
    $transaction_started = false;

    $search_plan = $db->query(
        "EXPLAIN SELECT id FROM entries
         WHERE MATCH(topic) AGAINST ('+needle*' IN BOOLEAN MODE)"
    )->fetch_assoc();
    if (($search_plan['key'] ?? null) !== 'ft_entries_topic') {
        throw new RuntimeException('Topic search did not use the FULLTEXT index.');
    }

    $listing_plan = $db->query(
        'EXPLAIN SELECT entries.id, entries.user_id, users.username, entries.topic, entries.language, entries.date
         FROM entries
         LEFT JOIN users ON entries.user_id = users.id
         ORDER BY entries.date DESC, entries.id DESC
         LIMIT 51'
    )->fetch_assoc();
    if (
        !in_array($listing_plan['key'] ?? null, ['idx_entries_cursor', 'idx_entries_date'], true)
        || str_contains(strtolower((string) ($listing_plan['Extra'] ?? '')), 'filesort')
    ) {
        throw new RuntimeException('Cursor listing did not use a date index without filesort.');
    }

    $quota_plan = $db->query(
        "EXPLAIN SELECT COALESCE(SUM(OCTET_LENGTH(text)), 0)
         FROM entries WHERE user_id = {$owner_id}"
    )->fetch_assoc();
    if (($quota_plan['key'] ?? null) !== 'idx_entries_user') {
        throw new RuntimeException('Account storage aggregation did not use the user index.');
    }

    echo "PASS: FULLTEXT, cursor-listing, and quota query plans use their indexes on 5,000 synthetic rows\n";
} finally {
    if ($transaction_started) {
        $db->rollback();
    }
    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $pattern = $prefix . '%';
    $cleanup = $db->prepare('DELETE FROM entries WHERE id LIKE ?');
    $cleanup->bind_param('s', $pattern);
    $cleanup->execute();
    $cleanup->close();
    $db->close();
}

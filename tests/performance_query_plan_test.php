<?php

if (getenv('OPNG_TEST_ALLOW_PERFORMANCE_FIXTURES') !== '1') {
    throw new RuntimeException('Run this query-plan test only against an isolated disposable database.');
}

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/includes/pagination_cursor.php';

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

    // A 500-node thread exercises the production history query and its
    // (root_id, date, id) index without loading the rest of the fixtures.
    $thread_root = $prefix . 'thread-root';
    $thread_stmt = $db->prepare(
        'INSERT INTO entries (id, parent_id, root_id, depth, user_id, language, text, topic, date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $thread_parent = null;
    $thread_root_id = $thread_root;
    $thread_text = 'Synthetic thread fixture.';
    $thread_language = 'text';
    $thread_date = gmdate('Y-m-d H:i:s', $base_timestamp);
    $thread_depth = 0;
    $thread_stmt->bind_param(
        'sssiissss',
        $thread_root,
        $thread_parent,
        $thread_root_id,
        $thread_depth,
        $owner_id,
        $thread_language,
        $thread_text,
        $thread_text,
        $thread_date
    );
    $thread_stmt->execute();
    $parent_id = $thread_root;
    for ($index = 1; $index <= 499; $index++) {
        $thread_id = $prefix . 'thread-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
        $depth = 1;
        $thread_topic = 'Thread reply ' . $index;
        $thread_date = gmdate('Y-m-d H:i:s', $base_timestamp + $index);
        $thread_stmt->bind_param(
            'sssiissss',
            $thread_id,
            $parent_id,
            $thread_root_id,
            $depth,
            $owner_id,
            $thread_language,
            $thread_text,
            $thread_topic,
            $thread_date
        );
        $thread_stmt->execute();
        $parent_id = $thread_id;
    }
    $thread_stmt->close();
    $thread_stmt = null;
    if (!$db->commit()) {
        throw new RuntimeException('Synthetic performance fixtures could not be committed.');
    }
    $transaction_started = false;

    // Refresh index statistics after the bulk load; otherwise the optimizer
    // can keep a stale "tiny table" plan and pick a filesort.
    $db->query('ANALYZE TABLE entries');

    $thread_sql = "SELECT entries.id, entries.parent_id, entries.root_id, entries.depth,
                          entries.topic, entries.date, u.username
                   FROM entries
                   LEFT JOIN users u ON u.id = entries.user_id
                   WHERE entries.root_id = '{$thread_root}'
                   ORDER BY entries.date ASC, entries.id ASC
                   LIMIT 501";
    $thread_plan = $db->query('EXPLAIN ' . $thread_sql)->fetch_assoc();
    if (
        ($thread_plan['key'] ?? null) !== 'idx_entries_root'
        || str_contains(strtolower((string) ($thread_plan['Extra'] ?? '')), 'filesort')
    ) {
        throw new RuntimeException('The history query did not use idx_entries_root without filesort.');
    }

    $thread_started = hrtime(true);
    $thread_result = $db->query($thread_sql);
    $thread_rows = $thread_result->fetch_all(MYSQLI_ASSOC);
    $thread_result->free();
    $thread_elapsed_ms = (hrtime(true) - $thread_started) / 1e6;
    if (count($thread_rows) !== 500) {
        throw new RuntimeException('The 500-node thread did not return every entry.');
    }
    if ($thread_elapsed_ms >= 100) {
        throw new RuntimeException('A 500-node history page took ' . round($thread_elapsed_ms, 2) . ' ms.');
    }

    $search_plan = $db->query(
        "EXPLAIN SELECT id FROM entries
         WHERE MATCH(topic) AGAINST ('+needle*' IN BOOLEAN MODE)"
    )->fetch_assoc();
    if (($search_plan['key'] ?? null) !== 'ft_entries_topic') {
        throw new RuntimeException('Topic search did not use the FULLTEXT index.');
    }

    $listing_plans = [];
    foreach ([['date', 'desc'], ['topic', 'asc'], ['language', 'asc']] as [$sort, $order]) {
        $listing = build_listing_query(null, null, null, $sort, $order);
        $listing_plan = $db->query('EXPLAIN ' . $listing['sql'])->fetch_assoc();
        $listing_plans[$sort] = $listing_plan;
    }

    $date_plan = $listing_plans['date'];
    if (
        !in_array($date_plan['key'] ?? null, ['idx_entries_cursor', 'idx_entries_date'], true)
        || str_contains(strtolower((string) ($date_plan['Extra'] ?? '')), 'filesort')
    ) {
        throw new RuntimeException('Cursor listing did not use a date index without filesort.');
    }
    if (
        ($listing_plans['topic']['key'] ?? null) !== 'idx_entries_topic'
        || str_contains(strtolower((string) ($listing_plans['topic']['Extra'] ?? '')), 'filesort')
    ) {
        throw new RuntimeException('Topic listing did not use idx_entries_topic without filesort.');
    }
    if (
        ($listing_plans['language']['key'] ?? null) !== 'idx_entries_language'
        || str_contains(strtolower((string) ($listing_plans['language']['Extra'] ?? '')), 'filesort')
    ) {
        throw new RuntimeException('Language listing did not use idx_entries_language without filesort.');
    }

    $quota_plan = $db->query(
        "EXPLAIN SELECT COALESCE(SUM(OCTET_LENGTH(text)), 0)
         FROM entries WHERE user_id = {$owner_id}"
    )->fetch_assoc();
    if (($quota_plan['key'] ?? null) !== 'idx_entries_user') {
        throw new RuntimeException('Account storage aggregation did not use the user index.');
    }

    // Admin user ordering and prefix searches must use the new users indexes.
    $db->query('ANALYZE TABLE users');
    $user_order_plan = $db->query(
        'EXPLAIN SELECT id, username, created_at, role FROM users
         ORDER BY created_at DESC, id DESC LIMIT 21'
    )->fetch_assoc();
    if (($user_order_plan['key'] ?? null) !== 'idx_users_created') {
        throw new RuntimeException('Admin user ordering did not use idx_users_created.');
    }
    $user_prefix_plan = $db->query(
        "EXPLAIN SELECT id FROM users WHERE username LIKE 'a%'"
    )->fetch_assoc();
    if (($user_prefix_plan['key'] ?? null) !== 'uniq_users_username') {
        throw new RuntimeException('A username prefix search did not use the unique index.');
    }

    echo "PASS: FULLTEXT, cursor-listing, history, quota, and admin user query plans use their indexes on synthetic rows\n";
} finally {
    if ($transaction_started) {
        $db->rollback();
    }
    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if (isset($thread_stmt) && $thread_stmt instanceof mysqli_stmt) {
        $thread_stmt->close();
    }
    $pattern = $prefix . '%';
    $cleanup = $db->prepare('DELETE FROM entries WHERE id LIKE ?');
    $cleanup->bind_param('s', $pattern);
    $cleanup->execute();
    $cleanup->close();
    $db->close();
}

<?php

if (getenv('OPNG_TEST_ALLOW_PERFORMANCE_FIXTURES') !== '1') {
    throw new RuntimeException('Run this query-plan test only against an isolated disposable database.');
}

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/includes/pagination_cursor.php';
require __DIR__ . '/../src/includes/thread.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
$db->set_charset('utf8mb4');

$prefix = 'runtime-perf-' . bin2hex(random_bytes(6)) . '-';
$stmt = null;
$thread_stmt = null;
$user_stmt = null;
$transaction_started = false;

try {
    $db->begin_transaction();
    $transaction_started = true;
    $base_timestamp = 1577836800;

    // The admin ordering plan is only meaningful once users is large enough
    // for the optimizer to prefer idx_users_created over a table scan.
    $fixture_hash = password_hash('runtime-perf-fixture', PASSWORD_DEFAULT);
    $user_stmt = $db->prepare(
        "INSERT INTO users (username, email, password_hash, role, created_at)
         VALUES (?, ?, ?, 'user', ?)"
    );
    $owner_id = 0;
    for ($index = 0; $index < 2000; $index++) {
        $username = ($index % 10 === 0 ? 'a' : 'z') . $prefix . 'user-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
        $email = $username . '@example.test';
        $created_at = gmdate('Y-m-d H:i:s', $base_timestamp + $index);
        $user_stmt->bind_param('ssss', $username, $email, $fixture_hash, $created_at);
        $user_stmt->execute();
        if ($index === 0) {
            $owner_id = (int) $db->insert_id;
        }
    }
    $user_stmt->close();
    $user_stmt = null;

    $stmt = $db->prepare(
        'INSERT INTO entries (id, user_id, author_label, language, text, topic, date, visibility)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    for ($index = 0; $index < 5000; $index++) {
        $id = $prefix . str_pad((string) $index, 5, '0', STR_PAD_LEFT);
        $author_label = 'perf-author-' . str_pad((string) ($index % 100), 3, '0', STR_PAD_LEFT);
        $language = 'text';
        $text = 'Synthetic performance-plan fixture.';
        $topic = $index % 2 === 0 ? 'needle performance match' : 'other performance topic';
        $date = gmdate('Y-m-d H:i:s', $base_timestamp + $index);
        // Mixed visibilities keep the viewer-aware listing plans honest: the
        // owner branch must not turn into a full scan or a filesort.
        $visibility = $index % 10 === 0 ? 'private' : ($index % 97 === 0 ? 'unlisted' : 'public');
        $stmt->bind_param('sissssss', $id, $owner_id, $author_label, $language, $text, $topic, $date, $visibility);
        $stmt->execute();
    }

    // A 500-node thread exercises the production history query and its
    // (root_id, date, id) index without loading the rest of the fixtures.
    $thread_root = $prefix . 'thread-root';
    $thread_stmt = $db->prepare(
        'INSERT INTO entries (id, parent_id, root_id, depth, user_id, author_label, language, text, topic, date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $thread_parent = null;
    $thread_root_id = $thread_root;
    $thread_text = 'Synthetic thread fixture.';
    $thread_language = 'text';
    $thread_author = 'perf-author-000';
    $thread_date = gmdate('Y-m-d H:i:s', $base_timestamp);
    $thread_depth = 0;
    $thread_stmt->bind_param(
        'sssiisssss',
        $thread_root,
        $thread_parent,
        $thread_root_id,
        $thread_depth,
        $owner_id,
        $thread_author,
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
            'sssiisssss',
            $thread_id,
            $parent_id,
            $thread_root_id,
            $depth,
            $owner_id,
            $thread_author,
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

    // EXPLAIN the exact SQL produced by thread_fetch_page(), including the
    // cursor-free UNION ALL form and the author label column (HYG-15).
    $thread_query = thread_page_query($thread_root);
    $thread_explain = $db->prepare('EXPLAIN ' . $thread_query['sql']);
    $thread_explain->bind_param($thread_query['types'], ...$thread_query['params']);
    $thread_explain->execute();
    $thread_plan_rows = $thread_explain->get_result()->fetch_all(MYSQLI_ASSOC);
    $thread_explain->close();
    $thread_plan_keys = array_column($thread_plan_rows, 'key');
    if (!in_array('idx_entries_root', $thread_plan_keys, true)) {
        throw new RuntimeException('The production history query did not use idx_entries_root.');
    }
    foreach ($thread_plan_rows as $thread_plan_row) {
        if (
            ($thread_plan_row['table'] ?? '') === 'entries'
            && ($thread_plan_row['type'] ?? '') === 'ALL'
        ) {
            throw new RuntimeException('A production history branch scanned the whole entries table.');
        }
    }

    $thread_started = hrtime(true);
    $thread_stmt = $db->prepare($thread_query['sql']);
    $thread_stmt->bind_param($thread_query['types'], ...$thread_query['params']);
    $thread_stmt->execute();
    $thread_result = $thread_stmt->get_result();
    $thread_rows = $thread_result->fetch_all(MYSQLI_ASSOC);
    $thread_result->free();
    $thread_stmt->close();
    $thread_stmt = null;
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
    foreach ([['date', 'desc'], ['topic', 'asc'], ['language', 'asc'], ['author', 'asc']] as [$sort, $order]) {
        $listing = build_listing_query(null, null, null, $sort, $order);
        $listing_plan = $db->query('EXPLAIN ' . $listing['sql'])->fetch_assoc();
        $listing_plans[$sort] = $listing_plan;
    }

    $date_plan = $listing_plans['date'];
    if (
        !in_array($date_plan['key'] ?? null, ['idx_entries_cursor', 'idx_entries_date', 'idx_entries_deleted'], true)
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
    if (
        ($listing_plans['author']['key'] ?? null) !== 'idx_entries_author'
        || str_contains(strtolower((string) ($listing_plans['author']['Extra'] ?? '')), 'filesort')
    ) {
        throw new RuntimeException('Author listing did not use idx_entries_author without filesort.');
    }

    // Signed-in listings add the viewer's own private entries. That OR must
    // keep the ordering index and must not require a filesort.
    $viewer_listing = build_listing_query(null, null, null, 'date', 'desc', $owner_id, 'user');
    $viewer_explain = $db->prepare('EXPLAIN ' . $viewer_listing['sql']);
    $viewer_param = $viewer_listing['params'][0];
    $viewer_explain->bind_param('i', $viewer_param);
    $viewer_explain->execute();
    $viewer_plan = $viewer_explain->get_result()->fetch_assoc();
    $viewer_explain->close();
    if (
        !in_array($viewer_plan['key'] ?? null, ['idx_entries_cursor', 'idx_entries_date', 'idx_entries_deleted'], true)
        || str_contains(strtolower((string) ($viewer_plan['Extra'] ?? '')), 'filesort')
    ) {
        throw new RuntimeException('The signed-in listing lost its date index or required a filesort.');
    }

    // The account quota reads the denormalized counter from the primary key
    // instead of aggregating the account's entries (PERF-10).
    $quota_plan = $db->query(
        "EXPLAIN SELECT role, used_bytes, username FROM users WHERE id = {$owner_id}"
    )->fetch_assoc();
    if (($quota_plan['key'] ?? null) !== 'PRIMARY') {
        throw new RuntimeException('Account quota lookup did not use the users primary key.');
    }
    $global_counter_plan = $db->query(
        "EXPLAIN SELECT used_bytes, used_entries FROM storage_counters
         WHERE counter_key = 'global'"
    )->fetch_assoc();
    if (($global_counter_plan['key'] ?? null) !== 'PRIMARY') {
        throw new RuntimeException('Global quota lookup did not use the storage counter primary key.');
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
    $unique_username_indexes = [];
    $unique_index_result = $db->query(
        "SELECT DISTINCT INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND NON_UNIQUE = 0
           AND COLUMN_NAME = 'username'"
    );
    while ($unique_index_row = $unique_index_result->fetch_assoc()) {
        $unique_username_indexes[] = $unique_index_row['INDEX_NAME'];
    }
    $unique_index_result->free();
    $prefix_key = $user_prefix_plan['key'] ?? null;
    if (!is_string($prefix_key) || !in_array($prefix_key, $unique_username_indexes, true)) {
        throw new RuntimeException('A username prefix search did not use the unique index.');
    }

    echo "PASS: FULLTEXT, cursor-listing (including author), production history, counter, and admin user query plans use their indexes on synthetic rows\n";
} finally {
    if ($transaction_started) {
        $db->rollback();
    }
    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if ($thread_stmt instanceof mysqli_stmt) {
        $thread_stmt->close();
    }
    if ($user_stmt instanceof mysqli_stmt) {
        $user_stmt->close();
    }
    $pattern = $prefix . '%';
    $user_pattern = '%' . $prefix . '%';
    $cleanup = $db->prepare('DELETE FROM entries WHERE id LIKE ?');
    $cleanup->bind_param('s', $pattern);
    $cleanup->execute();
    $cleanup->close();
    $cleanup_users = $db->prepare('DELETE FROM users WHERE username LIKE ?');
    $cleanup_users->bind_param('s', $user_pattern);
    $cleanup_users->execute();
    $cleanup_users->close();
    $db->close();
}

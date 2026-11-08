<?php
/**
 * Thread/history database integration test (F1).
 *
 * Creates a disposable database from database/schema.sql, builds root/reply/
 * nested-reply chains, checks chronological history and deletion semantics,
 * then drops the database. Opt-in only: requires
 * OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 and an account that can create/drop the
 * throwaway database. Never point it at production.
 */

require __DIR__ . '/../src/includes/schema.php';
require __DIR__ . '/../src/includes/thread.php';

function thread_integration_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function thread_integration_pass(string $message): void
{
    echo "PASS: {$message}\n";
}

if (getenv('OPNG_TEST_ALLOW_DATABASE_FIXTURES') !== '1') {
    echo "SKIP: set OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 to run the thread integration test.\n";
    exit(0);
}

$host = getenv('OPNG_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('OPNG_TEST_DB_PORT') ?: 3306);
$user = getenv('OPNG_TEST_DB_USER') ?: '';
$password = getenv('OPNG_TEST_DB_PASS') ?: '';

if ($user === '') {
    fwrite(STDERR, "SKIP: set OPNG_TEST_DB_USER (and OPNG_TEST_DB_PASS) to run the thread integration test.\n");
    exit(0);
}

$name = 'opng_thread_' . strtolower(bin2hex(random_bytes(4)));
if (preg_match('/\Aopng_thread_[a-z0-9]+\z/', $name) !== 1) {
    thread_integration_fail('unsafe disposable database name');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $admin = new mysqli($host, $user, $password, null, $port);
    $admin->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
    fwrite(STDERR, 'SKIP: could not connect to the disposable test server: ' . $exception->getMessage() . "\n");
    exit(0);
}

/**
 * Insert an entry using the same position rules as submit.php.
 */
function thread_integration_insert(
    mysqli $db,
    string $id,
    ?array $parent,
    ?int $user_id,
    string $topic,
    string $date
): void {
    $position = thread_position($parent, $id);
    $stmt = $db->prepare(
        'INSERT INTO entries (id, parent_id, root_id, depth, user_id, date, language, text, topic)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $text = 'thread fixture text';
    $language = 'text';
    $stmt->bind_param(
        'sssisssss',
        $id,
        $position['parent_id'],
        $position['root_id'],
        $position['depth'],
        $user_id,
        $date,
        $language,
        $text,
        $topic
    );
    $stmt->execute();
    $stmt->close();
}

try {
    $admin->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($host, $user, $password, $name, $port);
    $db->set_charset('utf8mb4');

    $schema_sql = file_get_contents(__DIR__ . '/../database/schema.sql');
    if (!is_string($schema_sql)) {
        thread_integration_fail('database/schema.sql is unreadable');
    }
    foreach (schema_split_sql($schema_sql) as $statement) {
        $db->query($statement);
    }
    thread_integration_pass('the canonical threaded schema is installed');

    $hash = password_hash('thread-fixture-password', PASSWORD_DEFAULT);
    $insert_user = $db->prepare(
        'INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, \'user\')'
    );
    $author = 'thread-author';
    $author_email = 'thread-author@example.test';
    $insert_user->bind_param('sss', $author, $author_email, $hash);
    $insert_user->execute();
    $author_id = (int) $insert_user->insert_id;
    $second = 'thread-second';
    $second_email = 'thread-second@example.test';
    $insert_user->bind_param('sss', $second, $second_email, $hash);
    $insert_user->execute();
    $second_id = (int) $insert_user->insert_id;
    $insert_user->close();

    $root_id = 'thread-root';
    thread_integration_insert(
        $db,
        $root_id,
        null,
        $author_id,
        'Thread root',
        '2024-01-01 10:00:00'
    );
    thread_integration_insert(
        $db,
        'thread-reply',
        thread_parent_for_reply($db, $root_id),
        $second_id,
        'RE: Thread root',
        '2024-01-01 10:05:00'
    );
    thread_integration_insert(
        $db,
        'thread-nested',
        thread_parent_for_reply($db, 'thread-reply'),
        null,
        'RE: RE: Thread root',
        '2024-01-01 10:10:00'
    );

    $stored = $db->query(
        "SELECT id, parent_id, root_id, depth FROM entries
         WHERE root_id = '{$root_id}' ORDER BY date ASC, id ASC"
    )->fetch_all(MYSQLI_ASSOC);
    if (
        count($stored) !== 3
        || $stored[0]['id'] !== 'thread-root'
        || $stored[0]['root_id'] !== 'thread-root'
        || (int) $stored[0]['depth'] !== 0
        || $stored[1]['parent_id'] !== 'thread-root'
        || (int) $stored[1]['depth'] !== 1
        || $stored[2]['parent_id'] !== 'thread-reply'
        || (int) $stored[2]['depth'] !== 2
    ) {
        thread_integration_fail('root/reply/nested positions were not stored as expected');
    }
    thread_integration_pass('a root, a reply and a nested reply store parent, root and depth');

    $page = thread_fetch_page($db, $root_id);
    if (count($page['nodes']) !== 3 || $page['truncated']) {
        thread_integration_fail('the thread page did not return the complete three-entry thread');
    }
    if (
        $page['nodes'][0]['id'] !== 'thread-root'
        || $page['nodes'][1]['id'] !== 'thread-reply'
        || $page['nodes'][2]['id'] !== 'thread-nested'
    ) {
        thread_integration_fail('the thread page is not in chronological order');
    }
    if (
        $page['nodes'][0]['username'] !== $author
        || $page['nodes'][1]['username'] !== $second
        || $page['nodes'][2]['username'] !== null
    ) {
        thread_integration_fail('the thread page did not join the expected authors');
    }
    thread_integration_pass('history lists root, reply and nested reply chronologically with authors');

    $grouped = thread_group($page['nodes']);
    $ancestors = thread_ancestor_ids($page['nodes'], 'thread-nested');
    if (
        $grouped['roots'] !== ['thread-root']
        || $grouped['children']['thread-root'] !== ['thread-reply']
        || $grouped['children']['thread-reply'] !== ['thread-nested']
        || !isset($ancestors['thread-reply'], $ancestors['thread-root'])
    ) {
        thread_integration_fail('the history tree or ancestor map is wrong');
    }
    thread_integration_pass('the branch tree and ancestor badges are derived from one query');

    $db->query("DELETE FROM entries WHERE id = 'thread-reply'");
    $orphan = $db->query(
        "SELECT parent_id, root_id, depth FROM entries WHERE id = 'thread-nested'"
    )->fetch_assoc();
    if (
        $orphan === null
        || $orphan['parent_id'] !== null
        || $orphan['root_id'] !== $root_id
        || (int) $orphan['depth'] !== 2
    ) {
        thread_integration_fail('deleting a reply did not detach its child while keeping the root');
    }
    thread_integration_pass('deleting a reply sets its child parent_id to NULL and keeps root_id');

    $db->query("DELETE FROM entries WHERE id = '{$root_id}'");
    $orphan = $db->query(
        "SELECT parent_id, root_id FROM entries WHERE id = 'thread-nested'"
    )->fetch_assoc();
    if ($orphan === null || $orphan['parent_id'] !== null || $orphan['root_id'] !== $root_id) {
        thread_integration_fail('deleting the root did not preserve the orphaned reply');
    }
    $orphan_page = thread_fetch_page($db, $root_id);
    if (count($orphan_page['nodes']) !== 1 || $orphan_page['nodes'][0]['id'] !== 'thread-nested') {
        thread_integration_fail('an orphaned subtree could not be fetched by its preserved root_id');
    }
    thread_integration_pass('deleting the root keeps the orphaned subtree findable');

    // Legacy rows can keep root_id = NULL: the migration that backfills roots
    // never ran on them, but replies already point at the root id. History must
    // still include the root itself instead of showing only the replies.
    $legacy_root = 'thread-legacy-root';
    $stmt = $db->prepare(
        'INSERT INTO entries (id, parent_id, root_id, depth, user_id, date, language, text, topic)
         VALUES (?, NULL, NULL, 0, ?, ?, ?, ?, ?)'
    );
    $legacy_date = '2024-01-15 10:00:00';
    $legacy_language = 'text';
    $legacy_text = 'legacy fixture text';
    $legacy_topic = 'Legacy root';
    $stmt->bind_param(
        'sissss',
        $legacy_root,
        $author_id,
        $legacy_date,
        $legacy_language,
        $legacy_text,
        $legacy_topic
    );
    $stmt->execute();
    $stmt->close();
    thread_integration_insert(
        $db,
        'thread-legacy-reply',
        thread_parent_for_reply($db, $legacy_root),
        $second_id,
        'RE: Legacy root',
        '2024-01-15 10:05:00'
    );
    $legacy_page = thread_fetch_page($db, $legacy_root);
    if (
        count($legacy_page['nodes']) !== 2
        || $legacy_page['nodes'][0]['id'] !== $legacy_root
        || $legacy_page['nodes'][0]['root_id'] !== null
        || $legacy_page['nodes'][1]['id'] !== 'thread-legacy-reply'
    ) {
        thread_integration_fail('a legacy root with root_id = NULL was dropped from its own history');
    }
    thread_integration_pass('history keeps a legacy root whose root_id is NULL');

    $db->query('DELETE FROM entries');

    $chain_root = 'thread-chain-root';
    thread_integration_insert(
        $db,
        $chain_root,
        null,
        $author_id,
        'Chain root',
        '2024-02-01 10:00:00'
    );
    $parent = thread_parent_for_reply($db, $chain_root);
    for ($depth = 1; $depth <= OPNG_MAX_THREAD_DEPTH; $depth++) {
        $id = 'thread-chain-' . $depth;
        thread_integration_insert($db, $id, $parent, null, 'Chain ' . $depth, '2024-02-01 10:00:00');
        $parent = thread_parent_for_reply($db, $id);
    }
    $too_deep = false;
    try {
        thread_position($parent, 'thread-chain-too-deep');
    } catch (ThreadDepthException) {
        $too_deep = true;
    }
    if (!$too_deep) {
        thread_integration_fail('a reply below depth 32 was accepted');
    }
    $max_depth = $db->query(
        "SELECT MAX(depth) AS max_depth FROM entries WHERE root_id = '{$chain_root}'"
    )->fetch_assoc();
    if ($max_depth === null || (int) $max_depth['max_depth'] !== OPNG_MAX_THREAD_DEPTH) {
        thread_integration_fail('the persisted chain did not reach exactly depth 32');
    }
    thread_integration_pass('a 33-entry chain stops at depth 32 and rejects one more level');

    $db->query('DELETE FROM entries');

    $page_root = 'thread-page-root';
    thread_integration_insert(
        $db,
        $page_root,
        null,
        null,
        'Paged thread root',
        '2024-02-15 10:00:00'
    );
    $page_parent = thread_parent_for_reply($db, $page_root);
    for ($index = 1; $index <= 501; $index++) {
        thread_integration_insert(
            $db,
            'thread-page-' . $index,
            $page_parent,
            null,
            'Paged reply ' . $index,
            gmdate('Y-m-d H:i:s', 1707991200 + $index)
        );
    }
    $first_page = thread_fetch_page($db, $page_root);
    if (
        count($first_page['nodes']) !== OPNG_THREAD_PAGE_SIZE
        || !$first_page['truncated']
        || $first_page['next_cursor'] === null
    ) {
        thread_integration_fail('the first history page was not capped at 500 entries with a cursor');
    }
    $cursor = decode_entry_cursor($first_page['next_cursor']);
    if ($cursor === null) {
        thread_integration_fail('the history cursor could not be decoded');
    }
    $second_page = thread_fetch_page($db, $page_root, $cursor);
    if (count($second_page['nodes']) !== 2 || $second_page['truncated']) {
        thread_integration_fail('the second history page did not return exactly the remaining entries');
    }
    $first_ids = array_column($first_page['nodes'], 'id');
    $second_ids = array_column($second_page['nodes'], 'id');
    if (array_intersect($first_ids, $second_ids) !== []) {
        thread_integration_fail('history pagination returned duplicate entries');
    }
    thread_integration_pass('a 502-entry thread pages at 500 nodes without duplicates');

    $db->query('DELETE FROM entries');

    $owner_root = 'thread-owner-root';
    thread_integration_insert(
        $db,
        $owner_root,
        null,
        $author_id,
        'Owner root',
        '2024-03-01 10:00:00'
    );
    thread_integration_insert(
        $db,
        'thread-owner-reply',
        thread_parent_for_reply($db, $owner_root),
        $second_id,
        'Owner reply',
        '2024-03-01 10:05:00'
    );

    // Same SQL as user_action.php delete_entries: deleting the author's rows
    // must detach other users' replies without deleting them.
    $stmt = $db->prepare('DELETE FROM entries WHERE user_id = ?');
    $stmt->bind_param('i', $author_id);
    $stmt->execute();
    $stmt->close();
    $kept = $db->query(
        "SELECT parent_id, root_id, user_id FROM entries WHERE id = 'thread-owner-reply'"
    )->fetch_assoc();
    if (
        $kept === null
        || $kept['parent_id'] !== null
        || $kept['root_id'] !== $owner_root
        || (int) $kept['user_id'] !== $second_id
    ) {
        thread_integration_fail('delete_entries did not preserve another user\'s reply');
    }
    thread_integration_pass('admin delete_entries preserves replies owned by other users');

    // Same SQL as user_action.php preserve: relations survive anonymisation.
    $stmt = $db->prepare('UPDATE entries SET user_id = NULL WHERE user_id = ?');
    $stmt->bind_param('i', $second_id);
    $stmt->execute();
    $stmt->close();
    $preserved = $db->query(
        "SELECT parent_id, root_id, user_id FROM entries WHERE id = 'thread-owner-reply'"
    )->fetch_assoc();
    if (
        $preserved === null
        || $preserved['parent_id'] !== null
        || $preserved['root_id'] !== $owner_root
        || $preserved['user_id'] !== null
    ) {
        thread_integration_fail('preserve did not anonymise the reply while keeping thread metadata');
    }
    thread_integration_pass('admin preserve keeps thread metadata after anonymisation');

    // Same statement as admin/empty.php.
    $db->query('DELETE FROM entries');
    $remaining = (int) $db->query('SELECT COUNT(*) AS total FROM entries')->fetch_assoc()['total'];
    if ($remaining !== 0) {
        thread_integration_fail('DELETE FROM entries left rows behind with the self foreign key');
    }
    thread_integration_pass('empty.php DELETE FROM entries works with the self-referencing foreign key');

    $db->close();
    $admin->query("DROP DATABASE `{$name}`");
} catch (mysqli_sql_exception | RuntimeException $exception) {
    if (isset($admin) && $admin instanceof mysqli) {
        try {
            $admin->query("DROP DATABASE IF EXISTS `{$name}`");
        } catch (mysqli_sql_exception) {
            // Best-effort cleanup; the original failure is more useful.
        }
    }
    thread_integration_fail($exception->getMessage());
}

echo "Thread integration passed: relations, history, deletion, depth limit and admin operations.\n";

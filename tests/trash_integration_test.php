<?php

declare(strict_types=1);

/**
 * Trash, restore and purge database integration test (10.3).
 *
 * Creates a disposable database from database/schema.sql, moves whole threads
 * to the trash, restores them, rejects purging live rows and verifies that the
 * storage counters stay correct through the retention purge. Opt-in only.
 */

require __DIR__ . '/../src/includes/schema.php';
require __DIR__ . '/../src/includes/user_quota.php';
require __DIR__ . '/../src/includes/trash.php';

function trash_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function trash_pass(string $message): void
{
    echo "PASS: {$message}\n";
}

function trash_assert(bool $condition, string $message): void
{
    if (!$condition) {
        trash_fail($message);
    }
    trash_pass($message);
}

function trash_insert_entry(
    mysqli $db,
    string $id,
    ?string $parent_id,
    ?string $root_id,
    int $depth,
    int $user_id,
    string $label,
    string $text
): void {
    $stmt = $db->prepare(
        'INSERT INTO entries (id, parent_id, root_id, depth, user_id, author_label, language, text, topic)
         VALUES (?, ?, ?, ?, ?, ?, \'text\', ?, ?)'
    );
    $topic = 'Trash fixture';
    $stmt->bind_param(
        'sssiisss',
        $id,
        $parent_id,
        $root_id,
        $depth,
        $user_id,
        $label,
        $text,
        $topic
    );
    $stmt->execute();
    $stmt->close();
}

function trash_seed_counters(mysqli $db): void
{
    $db->query(
        "INSERT INTO storage_counters (counter_key, used_bytes, used_entries)
         SELECT 'global', COALESCE(SUM(OCTET_LENGTH(text)), 0), COUNT(*) FROM entries
         ON DUPLICATE KEY UPDATE
             used_bytes = VALUES(used_bytes),
             used_entries = VALUES(used_entries)"
    );
    $db->query(
        'UPDATE users users
         SET used_bytes = (
             SELECT COALESCE(SUM(OCTET_LENGTH(entries.text)), 0)
             FROM entries WHERE entries.user_id = users.id
         )'
    );
}

function trash_counter_state(mysqli $db): array
{
    $global = $db->query(
        "SELECT used_bytes, used_entries FROM storage_counters WHERE counter_key = 'global'"
    )->fetch_assoc();

    return [
        'bytes' => (int) ($global['used_bytes'] ?? 0),
        'entries' => (int) ($global['used_entries'] ?? 0),
    ];
}

if (getenv('OPNG_TEST_ALLOW_DATABASE_FIXTURES') !== '1') {
    require_once __DIR__ . '/fixture_skip.php';
    fixture_skip("set OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 to run the trash integration test.");
}

$host = getenv('OPNG_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('OPNG_TEST_DB_PORT') ?: 3306);
$user = getenv('OPNG_TEST_DB_USER') ?: '';
$password = getenv('OPNG_TEST_DB_PASS') ?: '';

if ($user === '') {
    fwrite(STDERR, "FAIL: set OPNG_TEST_DB_USER (and OPNG_TEST_DB_PASS) to run the trash integration test.\n");
    exit(1);
}

$name = 'opng_trash_' . strtolower(bin2hex(random_bytes(4)));
if (preg_match('/\Aopng_trash_[a-z0-9]+\z/', $name) !== 1) {
    trash_fail('unsafe disposable database name');
}

putenv('OPNG_TRASH_RETENTION_DAYS');
trash_assert(trash_retention_days() === 7, 'the default retention window is seven days');
putenv('OPNG_TRASH_RETENTION_DAYS=3');
trash_assert(trash_retention_days() === 3, 'the retention window is configurable');
putenv('OPNG_TRASH_RETENTION_DAYS=0');
trash_assert(trash_retention_days() === 7, 'out-of-range retention values fall back to the default');
putenv('OPNG_TRASH_RETENTION_DAYS');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $admin = new mysqli($host, $user, $password, null, $port);
    $admin->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
    fwrite(STDERR, 'FAIL: could not connect to the disposable test server: ' . $exception->getMessage() . "\n");
    exit(1);
}

try {
    $admin->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($host, $user, $password, $name, $port);
    $db->set_charset('utf8mb4');

    $schema_sql = file_get_contents(__DIR__ . '/../database/schema.sql');
    if (!is_string($schema_sql)) {
        trash_fail('database/schema.sql is unreadable');
    }
    foreach (schema_split_sql($schema_sql) as $statement) {
        $db->query($statement);
    }

    $hash = password_hash('trash-fixture-password', PASSWORD_DEFAULT);
    $insert_user = $db->prepare(
        'INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, \'user\')'
    );
    $owner_name = 'trash-owner';
    $owner_email = 'trash-owner@example.test';
    $insert_user->bind_param('sss', $owner_name, $owner_email, $hash);
    $insert_user->execute();
    $owner_id = (int) $insert_user->insert_id;
    $reply_name = 'trash-reply';
    $reply_email = 'trash-reply@example.test';
    $insert_user->bind_param('sss', $reply_name, $reply_email, $hash);
    $insert_user->execute();
    $reply_id = (int) $insert_user->insert_id;
    $insert_user->close();

    trash_insert_entry($db, 'trash-root', null, 'trash-root', 0, $owner_id, $owner_name, 'root text');
    trash_insert_entry($db, 'trash-reply', 'trash-root', 'trash-root', 1, $reply_id, $reply_name, 'reply text');
    trash_insert_entry($db, 'trash-nested', 'trash-reply', 'trash-root', 2, $owner_id, $owner_name, 'nested text');
    trash_insert_entry($db, 'live-root', null, 'live-root', 0, $owner_id, $owner_name, 'live text');
    trash_seed_counters($db);
    trash_assert(trash_counter_state($db) === ['bytes' => 39, 'entries' => 4], 'counters start from the authoritative rows');

    trash_assert(trash_soft_delete_thread($db, 'trash-reply', $reply_id, 'user') === 0, 'reply owner cannot trash the thread');
    $updated = trash_soft_delete_thread($db, 'trash-reply', $owner_id, 'user');
    trash_assert($updated === 3, 'deleting a reply moves the whole thread to the trash');
    $live = $db->query("SELECT deleted_at FROM entries WHERE id = 'live-root'")->fetch_assoc();
    trash_assert(is_array($live) && $live['deleted_at'] === null, 'unrelated threads stay live');

    $thread = trash_thread_for_restore($db, 'trash-reply');
    trash_assert(
        is_array($thread)
            && $thread['id'] === 'trash-root'
            && $thread['user_id'] === $owner_id
            && $thread['entries'] === 3,
        'the trash resolves any thread member to its root'
    );
    $owned = trash_list_threads($db, $owner_id);
    $all = trash_list_threads($db);
    trash_assert(count($owned) === 1 && count($all) === 1, 'the trash lists one thread for owners and administrators');
    trash_assert($owned[0]['entries'] === 3, 'the trash counts the root and every reply once');

    $restored = trash_restore_thread($db, 'trash-reply');
    trash_assert($restored === 3, 'restoring a thread brings back the root and every reply');
    $live_count = (int) $db->query('SELECT COUNT(*) AS total FROM entries WHERE deleted_at IS NULL')->fetch_assoc()['total'];
    trash_assert($live_count === 4, 'the restored thread is live again');

    trash_soft_delete_thread($db, 'trash-root', $reply_id, 'admin');
    $refused = false;
    try {
        $refused = trash_purge_thread($db, 'live-root') === false;
    } catch (Throwable $exception) {
        trash_fail('purging a live thread raised: ' . $exception->getMessage());
    }
    $live = $db->query("SELECT id FROM entries WHERE id = 'live-root'")->fetch_assoc();
    trash_assert($refused && is_array($live), 'the administrator purge refuses live entries');

    trash_assert(trash_purge_thread($db, 'trash-reply'), 'a trashed thread can be purged immediately');
    $remaining = (int) $db->query('SELECT COUNT(*) AS total FROM entries')->fetch_assoc()['total'];
    trash_assert($remaining === 1, 'purging removes every row in the thread');
    trash_assert(
        trash_counter_state($db) === ['bytes' => 9, 'entries' => 1],
        'purging releases exactly the thread bytes and entries'
    );
    $owner_used = (int) $db->query('SELECT used_bytes FROM users WHERE id = ' . $owner_id)->fetch_assoc()['used_bytes'];
    $reply_used = (int) $db->query('SELECT used_bytes FROM users WHERE id = ' . $reply_id)->fetch_assoc()['used_bytes'];
    trash_assert($owner_used === 9 && $reply_used === 0, 'per-account counters are released for every author');

    trash_insert_entry($db, 'old-root', null, 'old-root', 0, $owner_id, $owner_name, 'old root');
    trash_insert_entry($db, 'old-reply', 'old-root', 'old-root', 1, $reply_id, $reply_name, 'old reply');
    trash_seed_counters($db);
    trash_soft_delete_thread($db, 'old-root', $owner_id, 'admin');
    $old = gmdate('Y-m-d H:i:s', time() - (30 * 86400));
    $db->query("UPDATE entries SET deleted_at = '{$old}' WHERE root_id = 'old-root'");
    $other = new mysqli($host, $user, $password, $name, $port);
    $other->query('SET SESSION innodb_lock_wait_timeout = 1');
    // Hold only the global row. Neither operation may reach the thread.
    $db->begin_transaction();
    global_quota_lock($db);
    foreach ([
        fn () => trash_restore_thread($other, 'old-reply'),
        fn () => trash_purge_expired($other, 7),
    ] as $operation) {
        $blocked = false;
        try {
            $operation();
        } catch (mysqli_sql_exception $exception) {
            $blocked = $exception->getCode() === 1205;
        }
        trash_assert($blocked, 'the operation waits for the global lock');
    }
    $db->commit();
    trash_assert(trash_restore_thread($other, 'old-reply') === 2, 'restore wins before purge');
    trash_assert(trash_purge_expired($db, 7)['purged'] === 0, 'purge skips restored rows');
    $checkCounters = static function () use ($db): void {
        $rows = $db->query('SELECT COUNT(*), SUM(OCTET_LENGTH(text)) FROM entries')->fetch_row();
        trash_assert(trash_counter_state($db) === ['bytes' => (int) $rows[1], 'entries' => (int) $rows[0]], 'global totals match rows');
        $bad = $db->query('SELECT id FROM users WHERE used_bytes <> (SELECT COALESCE(SUM(OCTET_LENGTH(text)),0) FROM entries WHERE user_id = users.id)');
        trash_assert($bad->num_rows === 0, 'each account matches its rows');
    };
    $checkCounters();
    trash_soft_delete_thread($db, 'old-root', $owner_id, 'admin');
    $db->query("UPDATE entries SET deleted_at = '{$old}' WHERE root_id = 'old-root'");
    $result = trash_purge_expired($db, 7);
    trash_assert(trash_restore_thread($other, 'old-reply') === 0, 'restore cannot revive purged rows');
    $checkCounters();
    $other->close();
    trash_assert($result['purged'] === 2 && $result['bytes'] === 17, 'the scheduled purge removes only expired trashed rows');
    trash_assert(
        trash_counter_state($db) === ['bytes' => 9, 'entries' => 1],
        'the scheduled purge keeps the counters aligned'
    );

    // A large backlog is drained by bounded runs that resume on the next call.
    foreach (['batch-a', 'batch-b', 'batch-c'] as $index => $batchId) {
        trash_insert_entry($db, $batchId, null, $batchId, 0, $owner_id, $owner_name, 'batch ' . $index);
    }
    trash_seed_counters($db);
    foreach (['batch-a', 'batch-b', 'batch-c'] as $batchId) {
        trash_soft_delete_thread($db, $batchId, $owner_id, 'admin');
    }
    $db->query(
        "UPDATE entries SET deleted_at = '{$old}' WHERE id IN ('batch-a', 'batch-b', 'batch-c')"
    );
    $firstRun = trash_purge_expired($db, 7, 2, 20);
    trash_assert(
        $firstRun['threads'] === 2 && $firstRun['purged'] === 2 && $firstRun['remaining'] === 1,
        'a bounded purge stops after its thread budget and reports the backlog'
    );
    $checkCounters();
    $secondRun = trash_purge_expired($db, 7, 2, 20);
    trash_assert(
        $secondRun['threads'] === 1 && $secondRun['remaining'] === 0,
        'the next purge run resumes and drains the remaining backlog'
    );
    $checkCounters();

    $db->close();
    $admin->query("DROP DATABASE `{$name}`");
} catch (mysqli_sql_exception | RuntimeException $exception) {
    if (isset($admin) && $admin instanceof mysqli) {
        try {
            $admin->query("DROP DATABASE IF EXISTS `{$name}`");
        } catch (mysqli_sql_exception) {
            // Best-effort cleanup.
        }
    }
    trash_fail($exception->getMessage());
}

echo "Trash integration passed: thread soft-delete, restore, purge guards, retention and counters.\n";

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
    echo "SKIP: set OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 to run the trash integration test.\n";
    exit(0);
}

$host = getenv('OPNG_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('OPNG_TEST_DB_PORT') ?: 3306);
$user = getenv('OPNG_TEST_DB_USER') ?: '';
$password = getenv('OPNG_TEST_DB_PASS') ?: '';

if ($user === '') {
    fwrite(STDERR, "SKIP: set OPNG_TEST_DB_USER (and OPNG_TEST_DB_PASS) to run the trash integration test.\n");
    exit(0);
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
    fwrite(STDERR, 'SKIP: could not connect to the disposable test server: ' . $exception->getMessage() . "\n");
    exit(0);
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

    $updated = trash_soft_delete_thread($db, 'trash-reply');
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

    trash_soft_delete_thread($db, 'trash-root');
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
    trash_soft_delete_thread($db, 'old-root');
    $old = gmdate('Y-m-d H:i:s', time() - (30 * 86400));
    $db->query("UPDATE entries SET deleted_at = '{$old}' WHERE root_id = 'old-root'");
    $result = trash_purge_expired($db, 7);
    trash_assert($result['purged'] === 2 && $result['bytes'] === 17, 'the scheduled purge removes only expired trashed rows');
    trash_assert(
        trash_counter_state($db) === ['bytes' => 9, 'entries' => 1],
        'the scheduled purge keeps the counters aligned'
    );

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

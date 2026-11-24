<?php
/**
 * Behavioural concurrency test for the write paths (HYG-14).
 *
 * The application serializes writes with the documented lock order
 * `storage_counters -> users -> entries`. This test opens two independent
 * connections and proves that:
 *
 *   - a second writer waits on the global counter instead of deadlocking;
 *   - the waiting writer re-reads the quota and rejects an overshoot;
 *   - concurrent edits/deletes keep the denormalized counters in sync;
 *   - a concurrent administrative role change follows the same order.
 *
 * Run only against a throwaway MySQL/MariaDB server:
 *
 *   OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 \
 *   OPNG_TEST_DB_HOST=127.0.0.1 OPNG_TEST_DB_PORT=3306 \
 *   OPNG_TEST_DB_USER=root OPNG_TEST_DB_PASS=... \
 *   php tests/write_concurrency_integration_test.php
 */

require __DIR__ . '/../src/includes/schema.php';
require __DIR__ . '/../src/includes/user_quota.php';

function write_concurrency_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function write_concurrency_pass(string $message): void
{
    echo "PASS: {$message}\n";
}

/**
 * Acquire the application locks in order: counters, account, entry.
 *
 * @return array{global: int, user: array{role: string, used_bytes: int, username: string}, bytes: int|null}
 */
function write_concurrency_acquire(mysqli $db, int $user_id, ?string $entry_id = null): array
{
    $global_used = global_quota_lock($db);
    if ($global_used === null) {
        throw new RuntimeException('Global storage counter is unavailable.');
    }

    $user = lock_user_quota($db, $user_id);
    if ($user === null) {
        throw new RuntimeException('Account row is unavailable.');
    }

    $bytes = null;
    if ($entry_id !== null) {
        $stmt = $db->prepare('SELECT OCTET_LENGTH(text) AS bytes FROM entries WHERE id = ? FOR UPDATE');
        if (!$stmt) {
            throw new RuntimeException('Entry lookup could not be prepared.');
        }
        $stmt->bind_param('s', $entry_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $stmt->close();
        if (!is_array($row)) {
            throw new RuntimeException("Entry {$entry_id} is unavailable.");
        }
        $bytes = (int) ($row['bytes'] ?? 0);
    }

    return ['global' => $global_used, 'user' => $user, 'bytes' => $bytes];
}

/**
 * Run an operation that must block on the global counter instead of reaching
 * a later resource or deadlocking.
 */
function write_concurrency_expect_wait(mysqli $db, callable $operation, string $label): void
{
    try {
        $operation();
    } catch (mysqli_sql_exception $exception) {
        $code = (int) $exception->getCode();
        if ($code === 1213 || str_contains($exception->getMessage(), 'Deadlock')) {
            write_concurrency_fail("{$label} deadlocked instead of waiting");
        }
        if ($code !== 1205 && !str_contains($exception->getMessage(), 'Lock wait timeout')) {
            write_concurrency_fail("{$label} failed with an unexpected error: " . $exception->getMessage());
        }

        write_concurrency_pass("{$label} waits at storage_counters without a deadlock");
        return;
    }

    write_concurrency_fail("{$label} did not block on the global counter");
}

/**
 * Restore counters and replace the entry fixture for the next scenario.
 */
function write_concurrency_reset(
    mysqli $db,
    int $user_id,
    int $user_bytes,
    int $global_bytes,
    int $entries
): void {
    $db->query('DELETE FROM entries');
    $db->query("UPDATE users SET used_bytes = {$user_bytes} WHERE id = {$user_id}");
    $db->query(
        "UPDATE storage_counters
         SET used_bytes = {$global_bytes}, used_entries = {$entries}
         WHERE counter_key = 'global'"
    );
}

/**
 * Insert an entry with exactly $bytes bytes of text.
 */
function write_concurrency_insert(mysqli $db, string $id, ?int $user_id, int $bytes): void
{
    $text = str_repeat('x', $bytes);
    $topic = 'Concurrency fixture';
    $stmt = $db->prepare(
        'INSERT INTO entries (id, user_id, author_label, language, text, topic)
         VALUES (?, ?, ?, \'text\', ?, ?)'
    );
    $owner = $user_id === null ? 'Guest' : 'race-user';
    $stmt->bind_param('sisss', $id, $user_id, $owner, $text, $topic);
    $stmt->execute();
    $stmt->close();
}

if (getenv('OPNG_TEST_ALLOW_DATABASE_FIXTURES') !== '1') {
    require_once __DIR__ . '/fixture_skip.php';
    fixture_skip("set OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 to run the write concurrency test.");
}

$host = getenv('OPNG_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('OPNG_TEST_DB_PORT') ?: 3306);
$user = getenv('OPNG_TEST_DB_USER') ?: '';
$password = getenv('OPNG_TEST_DB_PASS') ?: '';

if ($user === '') {
    fwrite(STDERR, "FAIL: set OPNG_TEST_DB_USER (and OPNG_TEST_DB_PASS) to run the write concurrency test.\n");
    exit(1);
}

$name = 'opng_write_' . strtolower(bin2hex(random_bytes(4)));
if (preg_match('/\Aopng_write_[a-z0-9]+\z/', $name) !== 1) {
    write_concurrency_fail('unsafe disposable database name');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$admin = null;
$first = null;
$second = null;

try {
    $admin = new mysqli($host, $user, $password, null, $port);
    $admin->set_charset('utf8mb4');
    $admin->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $setup = new mysqli($host, $user, $password, $name, $port);
    $setup->set_charset('utf8mb4');
    $schema_sql = file_get_contents(__DIR__ . '/../database/schema.sql');
    if (!is_string($schema_sql)) {
        write_concurrency_fail('database/schema.sql is unreadable');
    }
    foreach (schema_split_sql($schema_sql) as $statement) {
        $setup->query($statement);
    }

    $hash = password_hash('write-concurrency-password', PASSWORD_DEFAULT);
    $insert_user = $setup->prepare(
        "INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)"
    );
    $usernames = ['race-user', 'race-admin'];
    $emails = ['race-user@example.test', 'race-admin@example.test'];
    $roles = ['user', 'admin'];
    $user_ids = [];
    for ($index = 0; $index < 2; $index++) {
        $insert_user->bind_param('ssss', $usernames[$index], $emails[$index], $hash, $roles[$index]);
        $insert_user->execute();
        $user_ids[] = (int) $insert_user->insert_id;
    }
    $insert_user->close();
    $setup->close();
    write_concurrency_pass('the disposable schema and two accounts are installed');

    $first = new mysqli($host, $user, $password, $name, $port);
    $second = new mysqli($host, $user, $password, $name, $port);
    $first->set_charset('utf8mb4');
    $second->set_charset('utf8mb4');
    $first->query('SET SESSION innodb_lock_wait_timeout = 1');
    $second->query('SET SESSION innodb_lock_wait_timeout = 1');

    // ---------------------------------------------------------------------
    // 1. Quota race: the waiting writer must re-read the counter and reject.
    // ---------------------------------------------------------------------
    $user_id = $user_ids[0];
    $near_limit = OPNG_USER_QUOTA_BYTES - 60;
    write_concurrency_reset($first, $user_id, $near_limit, $near_limit, 0);

    $first->begin_transaction();
    $locked = write_concurrency_acquire($first, $user_id);
    if ($locked['user']['used_bytes'] !== $near_limit) {
        write_concurrency_fail('the quota fixture did not start at the expected usage');
    }
    if (!user_quota_allows($locked['user'], 0, 50)) {
        write_concurrency_fail('the first writer was rejected before reaching the limit');
    }
    write_concurrency_insert($first, 'race-accepted', $user_id, 50);
    if (
        !global_quota_consume($first, 50)
        || !global_entry_consume($first, 1)
        || !user_quota_consume($first, $user_id, 50)
    ) {
        write_concurrency_fail('the first writer could not update the counters');
    }

    $second->begin_transaction();
    write_concurrency_expect_wait(
        $second,
        static function () use ($second, $user_id): void {
            write_concurrency_acquire($second, $user_id);
        },
        'a concurrent create'
    );
    $second->rollback();
    $first->commit();

    $second->begin_transaction();
    $after_wait = write_concurrency_acquire($second, $user_id);
    if (user_quota_allows($after_wait['user'], 0, 50)) {
        write_concurrency_fail('the waiting writer did not re-check the updated quota');
    }
    $second->rollback();

    $entries = (int) $second->query('SELECT COUNT(*) AS total FROM entries')->fetch_assoc()['total'];
    $used = (int) $second->query('SELECT used_bytes FROM users WHERE id = ' . $user_id)->fetch_assoc()['used_bytes'];
    if ($entries !== 1 || $used !== $near_limit + 50) {
        write_concurrency_fail('the quota race changed the counters or inserted two entries');
    }
    write_concurrency_pass('the waiting create re-reads the quota and prevents an overshoot');

    // ---------------------------------------------------------------------
    // 2. Two editors: serialize and keep used_bytes equal to the text size.
    // ---------------------------------------------------------------------
    write_concurrency_reset($first, $user_id, 100, 100, 1);
    write_concurrency_insert($first, 'race-edit', $user_id, 100);

    $first->begin_transaction();
    write_concurrency_acquire($first, $user_id, 'race-edit');
    $first->query("UPDATE entries SET text = REPEAT('y', 120) WHERE id = 'race-edit'");
    if (
        !global_quota_consume($first, 20)
        || !user_quota_consume($first, $user_id, 20)
    ) {
        write_concurrency_fail('the first editor could not apply its counter delta');
    }

    $second->begin_transaction();
    write_concurrency_expect_wait(
        $second,
        static function () use ($second, $user_id): void {
            write_concurrency_acquire($second, $user_id, 'race-edit');
        },
        'a concurrent edit'
    );
    $second->rollback();
    $first->commit();

    $second->begin_transaction();
    $locked = write_concurrency_acquire($second, $user_id, 'race-edit');
    if ($locked['bytes'] !== 120 || $locked['user']['used_bytes'] !== 120) {
        write_concurrency_fail('the second editor did not see the committed text and counter');
    }
    $second->query("UPDATE entries SET text = REPEAT('z', 90) WHERE id = 'race-edit'");
    if (
        !global_quota_consume($second, -30)
        || !user_quota_consume($second, $user_id, -30)
    ) {
        write_concurrency_fail('the second editor could not apply its counter delta');
    }
    $second->commit();

    $final = $second->query(
        "SELECT OCTET_LENGTH(text) AS bytes, (SELECT used_bytes FROM users WHERE id = {$user_id}) AS used
         FROM entries WHERE id = 'race-edit'"
    )->fetch_assoc();
    if (
        !is_array($final)
        || (int) $final['bytes'] !== 90
        || (int) $final['used'] !== 90
    ) {
        write_concurrency_fail('concurrent edits left the account counter out of sync');
    }
    write_concurrency_pass('concurrent edits serialize and keep the account counter exact');

    // ---------------------------------------------------------------------
    // 3. Double delete: only the transaction that removes the row decrements.
    // ---------------------------------------------------------------------
    write_concurrency_reset($first, $user_id, 40, 40, 1);
    write_concurrency_insert($first, 'race-delete', $user_id, 40);

    $first->begin_transaction();
    write_concurrency_acquire($first, $user_id, 'race-delete');
    $first->query("DELETE FROM entries WHERE id = 'race-delete'");
    if ($first->affected_rows !== 1) {
        write_concurrency_fail('the first delete removed no row');
    }
    if (
        !global_quota_consume($first, -40)
        || !global_entry_consume($first, -1)
        || !user_quota_consume($first, $user_id, -40)
    ) {
        write_concurrency_fail('the first delete could not apply its counter delta');
    }

    $second->begin_transaction();
    write_concurrency_expect_wait(
        $second,
        static function () use ($second, $user_id): void {
            write_concurrency_acquire($second, $user_id);
        },
        'a concurrent delete'
    );
    $second->rollback();
    $first->commit();

    $second->begin_transaction();
    write_concurrency_acquire($second, $user_id);
    $second->query("DELETE FROM entries WHERE id = 'race-delete'");
    if ($second->affected_rows !== 0) {
        write_concurrency_fail('the second delete removed the row twice');
    }
    $second->commit();

    $counter = $second->query(
        "SELECT used_bytes, used_entries FROM storage_counters WHERE counter_key = 'global'"
    )->fetch_assoc();
    $account = (int) $second->query('SELECT used_bytes FROM users WHERE id = ' . $user_id)->fetch_assoc()['used_bytes'];
    if (
        !is_array($counter)
        || (int) $counter['used_bytes'] !== 0
        || (int) $counter['used_entries'] !== 0
        || $account !== 0
    ) {
        write_concurrency_fail('the double delete left stale counters');
    }
    write_concurrency_pass('a double delete decrements the counters once');

    // ---------------------------------------------------------------------
    // 4. Administrative role change follows the same counters -> users order.
    // ---------------------------------------------------------------------
    $target_id = $user_ids[1];
    $first->begin_transaction();
    write_concurrency_acquire($first, $target_id);
    $first->query("UPDATE users SET role = 'user' WHERE id = {$target_id}");

    $second->begin_transaction();
    write_concurrency_expect_wait(
        $second,
        static function () use ($second, $target_id): void {
            write_concurrency_acquire($second, $target_id);
        },
        'a concurrent role change'
    );
    $second->rollback();
    $first->commit();

    $second->begin_transaction();
    write_concurrency_acquire($second, $target_id);
    $second->query("UPDATE users SET role = 'admin' WHERE id = {$target_id}");
    $second->commit();

    $role = (string) $second->query('SELECT role FROM users WHERE id = ' . $target_id)->fetch_assoc()['role'];
    if ($role !== 'admin') {
        write_concurrency_fail('the waiting role change did not see the committed account');
    }
    write_concurrency_pass('a concurrent role change waits at the counter and then applies');

    $first->close();
    $first = null;
    $second->close();
    $second = null;
} catch (mysqli_sql_exception | RuntimeException $exception) {
    if ($first instanceof mysqli) {
        try {
            $first->rollback();
        } catch (mysqli_sql_exception) {
            // Best-effort cleanup; the original failure is more useful.
        }
        $first->close();
    }
    if ($second instanceof mysqli) {
        try {
            $second->rollback();
        } catch (mysqli_sql_exception) {
            // Best-effort cleanup; the original failure is more useful.
        }
        $second->close();
    }
    write_concurrency_fail($exception->getMessage());
} finally {
    if ($admin instanceof mysqli) {
        try {
            $admin->query("DROP DATABASE IF EXISTS `{$name}`");
        } catch (mysqli_sql_exception) {
            // Best-effort cleanup.
        }
        $admin->close();
    }
}

echo "Write concurrency contracts passed: quota, edit, delete and role changes serialize without deadlocks.\n";

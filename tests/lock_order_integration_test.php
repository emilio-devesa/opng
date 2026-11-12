<?php
/**
 * Concurrency integration test for the global lock order (ROB-15).
 *
 * Creates a disposable database, opens two independent connections and proves
 * that a second writer waits at the first resource in the documented order
 * instead of acquiring later resources and deadlocking. Run only against a
 * throwaway MySQL/MariaDB server:
 *
 *   OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 \
 *   OPNG_TEST_DB_HOST=127.0.0.1 OPNG_TEST_DB_PORT=3306 \
 *   OPNG_TEST_DB_USER=root OPNG_TEST_DB_PASS=... \
 *   php tests/lock_order_integration_test.php
 */

require __DIR__ . '/../src/includes/schema.php';

function lock_integration_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function lock_integration_pass(string $message): void
{
    echo "PASS: {$message}\n";
}

/**
 * Lock storage_counters, then users, then entries: the application order.
 */
function lock_integration_acquire(mysqli $db, int $user_id, string $entry_id): void
{
    $result = $db->query(
        "SELECT used_bytes FROM storage_counters WHERE counter_key = 'global' FOR UPDATE"
    );
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    $stmt = $db->prepare('SELECT id FROM entries WHERE id = ? FOR UPDATE');
    $stmt->bind_param('s', $entry_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();
}

if (getenv('OPNG_TEST_ALLOW_DATABASE_FIXTURES') !== '1') {
    echo "SKIP: set OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 to run the lock-order integration test.\n";
    exit(0);
}

$host = getenv('OPNG_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('OPNG_TEST_DB_PORT') ?: 3306);
$user = getenv('OPNG_TEST_DB_USER') ?: '';
$password = getenv('OPNG_TEST_DB_PASS') ?: '';

if ($user === '') {
    fwrite(STDERR, "SKIP: set OPNG_TEST_DB_USER (and OPNG_TEST_DB_PASS) to run the lock-order integration test.\n");
    exit(0);
}

$name = 'opng_lock_' . strtolower(bin2hex(random_bytes(4)));
if (preg_match('/\Aopng_lock_[a-z0-9]+\z/', $name) !== 1) {
    lock_integration_fail('unsafe disposable database name');
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
        lock_integration_fail('database/schema.sql is unreadable');
    }
    foreach (schema_split_sql($schema_sql) as $statement) {
        $setup->query($statement);
    }

    $hash = password_hash('lock-fixture-password', PASSWORD_DEFAULT);
    $stmt = $setup->prepare(
        "INSERT INTO users (username, email, password_hash, role) VALUES ('lock-user', 'lock-user@example.test', ?, 'user')"
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $user_id = (int) $stmt->insert_id;
    $stmt->close();

    $setup->query(
        "INSERT INTO entries (id, user_id, language, text, topic) VALUES ('lock-entry', {$user_id}, 'text', 'fixture', 'Fixture')"
    );
    $setup->close();
    lock_integration_pass('the disposable schema and lock fixtures are installed');

    $first = new mysqli($host, $user, $password, $name, $port);
    $second = new mysqli($host, $user, $password, $name, $port);
    $first->set_charset('utf8mb4');
    $second->set_charset('utf8mb4');
    // Keep the expected wait short; the second writer must block on the first
    // resource, not proceed to a later one.
    $first->query('SET SESSION innodb_lock_wait_timeout = 1');
    $second->query('SET SESSION innodb_lock_wait_timeout = 1');

    $first->begin_transaction();
    lock_integration_acquire($first, $user_id, 'lock-entry');

    $second->begin_transaction();
    $blocked = false;
    try {
        lock_integration_acquire($second, $user_id, 'lock-entry');
    } catch (mysqli_sql_exception $exception) {
        $blocked = (int) $exception->getCode() === 1205
            || str_contains($exception->getMessage(), 'Lock wait timeout');
    }
    if (!$blocked) {
        lock_integration_fail('a second writer did not wait on the global counter first');
    }
    $second->rollback();
    lock_integration_pass('a concurrent writer waits at storage_counters instead of taking a later lock');

    $first->commit();

    // Once the first transaction releases the order, the second one completes
    // without a deadlock or a timeout.
    $second->begin_transaction();
    lock_integration_acquire($second, $user_id, 'lock-entry');
    $second->commit();
    lock_integration_pass('the waiting writer completes after the first transaction commits');

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
    lock_integration_fail($exception->getMessage());
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

echo "Lock-order integration passed: concurrent writers serialize on storage_counters -> users -> entries.\n";

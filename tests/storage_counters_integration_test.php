<?php
/**
 * Denormalized storage-counter integration test (PERF-10/PERF-17).
 *
 * Creates a disposable database from database/schema.sql, writes entries
 * directly (as an import or seed would), rebuilds the counters and verifies
 * that the request-path helpers keep the global and per-account values in
 * sync. Opt-in only: requires OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 and an
 * account that can create/drop the throwaway database.
 */

require __DIR__ . '/../src/includes/schema.php';
require __DIR__ . '/../src/includes/user_quota.php';

function storage_counters_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function storage_counters_pass(string $message): void
{
    echo "PASS: {$message}\n";
}

if (getenv('OPNG_TEST_ALLOW_DATABASE_FIXTURES') !== '1') {
    require_once __DIR__ . '/fixture_skip.php';
    fixture_skip("set OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 to run the storage counter test.");
}

$host = getenv('OPNG_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('OPNG_TEST_DB_PORT') ?: 3306);
$user = getenv('OPNG_TEST_DB_USER') ?: '';
$password = getenv('OPNG_TEST_DB_PASS') ?: '';

if ($user === '') {
    fwrite(STDERR, "FAIL: set OPNG_TEST_DB_USER (and OPNG_TEST_DB_PASS) to run the storage counter test.\n");
    exit(1);
}

$name = 'opng_counters_' . strtolower(bin2hex(random_bytes(4)));
if (preg_match('/\Aopng_counters_[a-z0-9]+\z/', $name) !== 1) {
    storage_counters_fail('unsafe disposable database name');
}

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
        storage_counters_fail('database/schema.sql is unreadable');
    }
    foreach (schema_split_sql($schema_sql) as $statement) {
        $db->query($statement);
    }

    $hash = password_hash('counter-fixture-password', PASSWORD_DEFAULT);
    $insert_user = $db->prepare(
        "INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'user')"
    );
    $first_name = 'counter-first';
    $first_email = 'counter-first@example.test';
    $insert_user->bind_param('sss', $first_name, $first_email, $hash);
    $insert_user->execute();
    $first_id = (int) $insert_user->insert_id;
    $second_name = 'counter-second';
    $second_email = 'counter-second@example.test';
    $insert_user->bind_param('sss', $second_name, $second_email, $hash);
    $insert_user->execute();
    $second_id = (int) $insert_user->insert_id;
    $insert_user->close();

    $insert_entry = $db->prepare(
        'INSERT INTO entries (id, user_id, author_label, language, text, topic)
         VALUES (?, ?, ?, \'text\', ?, ?)'
    );
    foreach ([
        ['counter-entry-first', $first_id, $first_name, str_repeat('a', 100)],
        ['counter-entry-second', $second_id, $second_name, str_repeat('b', 50)],
        ['counter-entry-guest', null, 'Guest', str_repeat('c', 25)],
    ] as [$entry_id, $entry_user, $entry_label, $entry_text]) {
        $topic = 'Counter fixture';
        $insert_entry->bind_param('sisss', $entry_id, $entry_user, $entry_label, $entry_text, $topic);
        $insert_entry->execute();
    }
    $insert_entry->close();

    if (!user_quota_recount($db) || !global_quota_recount($db)) {
        storage_counters_fail('the reconciliation helpers failed');
    }

    $users = $db->query(
        'SELECT id, used_bytes FROM users ORDER BY id ASC'
    )->fetch_all(MYSQLI_ASSOC);
    if (
        count($users) !== 2
        || (int) $users[0]['used_bytes'] !== 100
        || (int) $users[1]['used_bytes'] !== 50
    ) {
        storage_counters_fail('user_quota_recount did not derive the account byte counters');
    }
    storage_counters_pass('user_quota_recount derives each account byte counter');

    $global = $db->query(
        "SELECT used_bytes, used_entries FROM storage_counters WHERE counter_key = 'global'"
    )->fetch_assoc();
    if (
        $global === null
        || (int) $global['used_bytes'] !== 175
        || (int) $global['used_entries'] !== 3
    ) {
        storage_counters_fail('global_quota_recount did not derive the global counters');
    }
    storage_counters_pass('global_quota_recount derives total bytes and entry count');

    $db->begin_transaction();
    $usage = lock_user_quota($db, $first_id);
    if ($usage === null || $usage['used_bytes'] !== 100 || $usage['username'] !== $first_name) {
        storage_counters_fail('lock_user_quota did not read the stored counter and username');
    }
    if (
        !user_quota_consume($db, $first_id, -100)
        || !global_quota_consume($db, -100)
        || !global_entry_consume($db, -1)
    ) {
        storage_counters_fail('the signed counter updates failed');
    }
    if (!$db->commit()) {
        storage_counters_fail('the counter transaction could not be committed');
    }
    $first_usage = $db->query(
        'SELECT used_bytes FROM users WHERE id = ' . $first_id
    )->fetch_assoc();
    $global = $db->query(
        "SELECT used_bytes, used_entries FROM storage_counters WHERE counter_key = 'global'"
    )->fetch_assoc();
    if (
        $first_usage === null
        || (int) $first_usage['used_bytes'] !== 0
        || $global === null
        || (int) $global['used_bytes'] !== 75
        || (int) $global['used_entries'] !== 2
    ) {
        storage_counters_fail('the signed deltas did not update account, byte and entry counters');
    }
    storage_counters_pass('account, byte and entry counters apply signed deltas atomically');

    $db->begin_transaction();
    if (!user_quota_consume($db, $first_id, -500) || !global_quota_consume($db, -500)) {
        storage_counters_fail('negative deltas could not be applied');
    }
    if (!$db->commit()) {
        storage_counters_fail('the clamp transaction could not be committed');
    }
    $clamped = $db->query(
        "SELECT (SELECT used_bytes FROM users WHERE id = {$first_id}) AS user_bytes,
                (SELECT used_bytes FROM storage_counters WHERE counter_key = 'global') AS global_bytes"
    )->fetch_assoc();
    if (
        $clamped === null
        || (int) $clamped['user_bytes'] !== 0
        || (int) $clamped['global_bytes'] !== 0
    ) {
        storage_counters_fail('negative deltas were not clamped at zero');
    }
    storage_counters_pass('negative counter deltas are clamped at zero');

    $db->begin_transaction();
    $locked_usage = global_quota_lock($db);
    if ($locked_usage !== 0) {
        storage_counters_fail('global_quota_lock did not return the stored global counter');
    }
    if (!global_quota_zero($db) || !user_quota_zero_all($db)) {
        storage_counters_fail('the bulk counter reset failed');
    }
    if (!$db->commit()) {
        storage_counters_fail('the reset transaction could not be committed');
    }
    $reset = $db->query(
        "SELECT (SELECT SUM(used_bytes) FROM users) AS user_bytes,
                (SELECT used_bytes FROM storage_counters WHERE counter_key = 'global') AS global_bytes,
                (SELECT used_entries FROM storage_counters WHERE counter_key = 'global') AS global_entries"
    )->fetch_assoc();
    if (
        $reset === null
        || (int) $reset['user_bytes'] !== 0
        || (int) $reset['global_bytes'] !== 0
        || (int) $reset['global_entries'] !== 0
    ) {
        storage_counters_fail('global_quota_zero/user_quota_zero_all did not clear the counters');
    }
    storage_counters_pass('bulk reset clears account, byte and entry counters');

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
    storage_counters_fail($exception->getMessage());
}

echo "Storage counter integration passed: recount, signed deltas, clamping and bulk reset.\n";

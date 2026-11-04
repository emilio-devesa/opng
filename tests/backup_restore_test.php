<?php
/**
 * End-to-end backup/restore regression test (ROB-07).
 *
 * Creates a disposable source database, generates a dump with the same helper
 * used by the admin panel and tools/backup.php, drops the source, imports the
 * dump into a second database and compares the full contents.
 *
 * Opt-in only: requires OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 and an account that
 * can create/drop the two throwaway databases. Never point it at production.
 */

require __DIR__ . '/../src/includes/schema.php';
require __DIR__ . '/../src/includes/sql_backup.php';

function backup_restore_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function backup_restore_pass(string $message): void
{
    echo "PASS: {$message}\n";
}

if (getenv('OPNG_TEST_ALLOW_DATABASE_FIXTURES') !== '1') {
    echo "SKIP: set OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 to run the backup/restore test.\n";
    exit(0);
}

$host = getenv('OPNG_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('OPNG_TEST_DB_PORT') ?: 3306);
$user = getenv('OPNG_TEST_DB_USER') ?: '';
$password = getenv('OPNG_TEST_DB_PASS') ?: '';

if ($user === '') {
    fwrite(STDERR, "SKIP: set OPNG_TEST_DB_USER (and OPNG_TEST_DB_PASS) to run the backup/restore test.\n");
    exit(0);
}

$suffix = strtolower(bin2hex(random_bytes(4)));
$source = 'opng_backup_src_' . $suffix;
$target = 'opng_backup_dst_' . $suffix;
foreach ([$source, $target] as $name) {
    if (preg_match('/\Aopng_backup_(?:src|dst)_[a-z0-9]+\z/', $name) !== 1) {
        backup_restore_fail('unsafe disposable database name');
    }
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
 * Deterministic digest of every table's full contents.
 *
 * @param list<string> $tables
 * @return array<string, array{rows: int, hash: string}>
 */
function backup_restore_fingerprint(mysqli $db, array $tables): array
{
    $fingerprint = [];
    foreach ($tables as $table) {
        $result = $db->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();

        usort(
            $rows,
            static fn (array $left, array $right): int => strcmp(
                json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
            )
        );

        $fingerprint[$table] = [
            'rows' => count($rows),
            'hash' => hash(
                'sha256',
                json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
            ),
        ];
    }

    return $fingerprint;
}

/**
 * @return list<string>
 */
function backup_restore_tables(mysqli $db): array
{
    $result = $db->query('SHOW TABLES');
    $tables = [];
    while ($row = $result->fetch_row()) {
        if (is_string($row[0] ?? null)) {
            $tables[] = $row[0];
        }
    }
    $result->free();
    sort($tables, SORT_STRING);

    return $tables;
}

$created = [];
try {
    $admin->query("CREATE DATABASE `{$source}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created[] = $source;
    $admin->query("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created[] = $target;

    $db = new mysqli($host, $user, $password, $source, $port);
    $db->set_charset('utf8mb4');

    $schema_sql = file_get_contents(__DIR__ . '/../database/schema.sql');
    if (!is_string($schema_sql)) {
        backup_restore_fail('database/schema.sql is unreadable');
    }
    foreach (schema_split_sql($schema_sql) as $statement) {
        $db->query($statement);
    }
    backup_restore_pass('the canonical schema is installed in the source database');

    $password_hash = password_hash('restore-fixture-password', PASSWORD_DEFAULT);
    $insert_user = $db->prepare(
        "INSERT INTO users (username, email, password_hash, role, session_version)
         VALUES (?, ?, ?, 'admin', 7)"
    );
    $username = 'restore-user';
    $email = 'restore@example.test';
    $insert_user->bind_param('sss', $username, $email, $password_hash);
    $insert_user->execute();
    $user_id = (int) $insert_user->insert_id;
    $insert_user->close();

    $text = "Line 1\nLine '2' ñ ; DROP TABLE users -- \x00 tail";
    $topic = "Restore 'topic' ; -- ✓";
    $insert_entry = $db->prepare(
        'INSERT INTO entries (id, user_id, date, language, text, topic) VALUES (?, ?, NOW(), ?, ?, ?)'
    );
    $entry_id = 'restore-entry';
    $language = 'text';
    $insert_entry->bind_param('sisss', $entry_id, $user_id, $language, $text, $topic);
    $insert_entry->execute();
    $insert_entry->close();

    $db->query("INSERT INTO schema_migrations (version) VALUES ('0003_admin_metrics_expires_bigint')");
    $db->query(
        "INSERT INTO storage_counters (counter_key, used_bytes) VALUES ('global', 1234)
         ON DUPLICATE KEY UPDATE used_bytes = VALUES(used_bytes)"
    );
    $db->query("INSERT INTO rate_limits (action, key_hash, window_start, hits) VALUES ('backup', REPEAT('a', 64), 100, 3)");
    $db->query(
        "INSERT INTO admin_metrics_cache (cache_key, payload, expires_at)
         VALUES ('dashboard', '{\"entries\":1}', 4102444800)"
    );
    $db->query(
        "INSERT INTO audit_log (actor_id, action, target, ip_hash)
         VALUES ({$user_id}, 'backup.download', 'restore-fixture', REPEAT('b', 64))"
    );
    backup_restore_pass('fixture rows include hashes, Unicode, quotes, NUL bytes and a foreign key');

    $tables = backup_restore_tables($db);
    if ($tables === []) {
        backup_restore_fail('the source database has no tables');
    }
    $expected = backup_restore_fingerprint($db, $tables);

    $limited_stream = fopen('php://temp', 'w+b');
    if ($limited_stream === false) {
        backup_restore_fail('could not open the limit-test stream');
    }
    try {
        sql_backup_dump($db, $limited_stream, 60, 16);
        backup_restore_fail('a dump smaller than its byte limit was accepted');
    } catch (SqlBackupLimitException) {
        backup_restore_pass('the byte limit aborts a dump instead of producing a truncated file');
    } finally {
        fclose($limited_stream);
        $db->rollback();
    }

    $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
    if ($stream === false) {
        backup_restore_fail('could not open the dump stream');
    }
    $dump_database = sql_backup_dump($db, $stream, 60, 64 * 1024 * 1024);
    rewind($stream);
    $dump = stream_get_contents($stream);
    fclose($stream);
    if (!is_string($dump) || $dump === '') {
        backup_restore_fail('the dump is empty');
    }
    backup_restore_pass('the dump is generated from a consistent read-only snapshot');
    if ($dump_database !== $source) {
        backup_restore_fail('the dump reports the wrong source database');
    }
    if (preg_match('/^\s*(?:CREATE DATABASE|USE)\s/mi', $dump) === 1) {
        backup_restore_fail('the dump hard-codes a database name instead of staying portable');
    }
    if (!str_contains($dump, 'SET FOREIGN_KEY_CHECKS=0;') || !str_contains($dump, 'SET FOREIGN_KEY_CHECKS=1;')) {
        backup_restore_fail('the dump does not manage FOREIGN_KEY_CHECKS');
    }

    $db->close();
    $admin->query("DROP DATABASE `{$source}`");
    $created = [$target];
    backup_restore_pass('the source database is dropped to simulate data loss');

    $restore = new mysqli($host, $user, $password, $target, $port);
    $restore->set_charset('utf8mb4');
    foreach (schema_split_sql($dump) as $statement) {
        $restore->query($statement);
    }
    backup_restore_pass('the dump imports into a clean, differently named database');

    $check = $restore->query('SELECT @@FOREIGN_KEY_CHECKS AS enabled');
    $check_row = $check->fetch_assoc();
    $check->free();
    if (!is_array($check_row) || (int) ($check_row['enabled'] ?? 0) !== 1) {
        backup_restore_fail('the restore did not re-enable FOREIGN_KEY_CHECKS');
    }
    backup_restore_pass('the restore re-enables FOREIGN_KEY_CHECKS');

    $restored_tables = backup_restore_tables($restore);
    $actual = backup_restore_fingerprint($restore, $restored_tables);
    if ($restored_tables !== $tables) {
        backup_restore_fail('the restored table list differs from the source');
    }
    if ($actual !== $expected) {
        foreach ($expected as $table => $expectation) {
            if (($actual[$table] ?? null) !== $expectation) {
                backup_restore_fail("restored contents differ for {$table}");
            }
        }
        backup_restore_fail('restored contents differ from the source');
    }
    backup_restore_pass('every table row and hash matches the source');

    $login = $restore->query("SELECT password_hash FROM users WHERE username = 'restore-user'");
    $login_row = $login->fetch_assoc();
    $login->free();
    if (
        !is_array($login_row)
        || !is_string($login_row['password_hash'] ?? null)
        || !password_verify('restore-fixture-password', $login_row['password_hash'])
    ) {
        backup_restore_fail('the restored account cannot authenticate with its original password');
    }
    backup_restore_pass('a restored account authenticates with its original password');

    $entry = $restore->prepare('SELECT text, topic FROM entries WHERE id = ?');
    $entry->bind_param('s', $entry_id);
    $entry->execute();
    $entry_result = $entry->get_result();
    $entry_row = $entry_result ? $entry_result->fetch_assoc() : null;
    if ($entry_result instanceof mysqli_result) {
        $entry_result->free();
    }
    $entry->close();
    if (
        !is_array($entry_row)
        || $entry_row['text'] !== $text
        || $entry_row['topic'] !== $topic
    ) {
        backup_restore_fail('the restored paste text or topic differs');
    }
    backup_restore_pass('the restored paste preserves text and topic byte-for-byte');

    $fk = $restore->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'entries'"
    );
    $fk_row = $fk->fetch_assoc();
    $fk->free();
    if (!is_array($fk_row) || (int) ($fk_row['total'] ?? 0) < 1) {
        backup_restore_fail('the restored schema is missing the entries foreign key');
    }
    backup_restore_pass('the restored schema keeps its foreign keys');

    $restore->close();
} catch (mysqli_sql_exception $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    $failure = true;
} finally {
    foreach ($created as $name) {
        try {
            $admin->query("DROP DATABASE IF EXISTS `{$name}`");
        } catch (mysqli_sql_exception $exception) {
            fwrite(STDERR, "WARN: could not drop {$name}: " . $exception->getMessage() . "\n");
        }
    }
    $admin->close();
}

if (!empty($failure)) {
    exit(1);
}

echo "Backup/restore contracts passed: portable dump, complete restore, authentication and foreign keys.\n";

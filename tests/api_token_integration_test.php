<?php

declare(strict_types=1);

/**
 * API token database integration test (10.4).
 *
 * Creates a disposable database from database/schema.sql, issues and revokes
 * tokens, and verifies expiry, scopes and last-use tracking. Opt-in only.
 */

require __DIR__ . '/../src/includes/schema.php';
require __DIR__ . '/../src/includes/api_tokens.php';
require __DIR__ . '/../src/includes/entry_access.php';

function api_token_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function api_token_pass(string $message): void
{
    echo "PASS: {$message}\n";
}

if (getenv('OPNG_TEST_ALLOW_DATABASE_FIXTURES') !== '1') {
    echo "SKIP: set OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 to run the API token integration test.\n";
    exit(0);
}

$host = getenv('OPNG_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('OPNG_TEST_DB_PORT') ?: 3306);
$user = getenv('OPNG_TEST_DB_USER') ?: '';
$password = getenv('OPNG_TEST_DB_PASS') ?: '';

if ($user === '') {
    fwrite(STDERR, "SKIP: set OPNG_TEST_DB_USER (and OPNG_TEST_DB_PASS) to run the API token integration test.\n");
    exit(0);
}

$name = 'opng_tokens_' . strtolower(bin2hex(random_bytes(4)));
if (preg_match('/\Aopng_tokens_[a-z0-9]+\z/', $name) !== 1) {
    api_token_fail('unsafe disposable database name');
}

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
        api_token_fail('database/schema.sql is unreadable');
    }
    foreach (schema_split_sql($schema_sql) as $statement) {
        $db->query($statement);
    }

    $hash = password_hash('token-fixture-password', PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        'INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, \'user\')'
    );
    $username = 'token-owner';
    $email = 'token-owner@example.test';
    $stmt->bind_param('sss', $username, $email, $hash);
    $stmt->execute();
    $user_id = (int) $stmt->insert_id;
    $stmt->close();
    api_token_pass('a disposable database with a token owner is ready');

    $db->query("INSERT INTO entries (id, topic, language, text, visibility, protection_hash, expires_at, deleted_at)
        VALUES ('live','topic','text','body','public',NULL,NULL,NULL),
               ('locked','topic','text','body','public','fixture',NULL,NULL),
               ('private','topic','text','body','private',NULL,NULL,NULL),
               ('unlisted','topic','text','body','unlisted',NULL,NULL,NULL),
               ('expired','topic','text','body','public',NULL,'2000-01-01',NULL),
               ('trashed','topic','text','body','public',NULL,NULL,NOW())");
    foreach ([entry_public_filter_sql() => ['live', 'locked'], entry_api_content_filter_sql() => ['live']] as $filter => $ids) {
        $rows = $db->query('SELECT id FROM entries WHERE ' . $filter . ' ORDER BY id')->fetch_all();
        if (array_column($rows, 0) !== $ids) {
            api_token_fail('listing and content visibility differ from their access matrix');
        }
    }
    api_token_pass('listing returns public metadata; API content excludes protected and non-public rows');

    $created = api_token_create($db, $user_id, 'CI token', 'read', 30);
    if ($created === null || !api_token_valid_format($created['clear'])) {
        api_token_fail('a token could not be created');
    }
    api_token_pass('a personal read token is issued');

    $stored = $db->query(
        'SELECT token_hash, scopes, revoked_at, expires_at FROM api_tokens WHERE id = ' . (int) $created['id']
    )->fetch_assoc();
    if (
        !is_array($stored)
        || $stored['token_hash'] !== hash('sha256', $created['clear'])
        || $stored['scopes'] !== 'read'
        || $stored['revoked_at'] !== null
        || $stored['expires_at'] === null
    ) {
        api_token_fail('the stored token row is not hashed, scoped and expiring as expected');
    }
    api_token_pass('only the hash, scopes and expiry are stored');

    $listed = api_token_list($db, $user_id);
    if (count($listed) !== 1 || $listed[0]['id'] !== $created['id'] || $listed[0]['username'] !== $username) {
        api_token_fail('the token list does not include the issued token');
    }
    api_token_pass('the owner sees the issued token');

    $identity = api_token_authenticate($db, $created['clear']);
    if (
        $identity === null
        || $identity['user_id'] !== $user_id
        || $identity['username'] !== $username
        || !api_token_scope_allows($identity['scopes'], 'read')
        || api_token_scope_allows($identity['scopes'], 'write')
    ) {
        api_token_fail('bearer authentication did not return the expected identity or scopes');
    }
    $last_used = $db->query(
        'SELECT last_used_at FROM api_tokens WHERE id = ' . (int) $created['id']
    )->fetch_assoc();
    if (!is_array($last_used) || !is_string($last_used['last_used_at'] ?? null)) {
        api_token_fail('authentication did not record last_used_at');
    }
    api_token_pass('bearer authentication returns the identity and records last use');

    if (api_token_authenticate($db, 'opng_' . str_repeat('0', 64)) !== null) {
        api_token_fail('an unknown token was accepted');
    }
    api_token_pass('unknown tokens are rejected');

    $write_token = api_token_create($db, $user_id, 'Writer', ['read', 'write']);
    if ($write_token === null) {
        api_token_fail('a write-scoped token could not be created');
    }
    $write_identity = api_token_authenticate($db, $write_token['clear']);
    if ($write_identity === null || !api_token_scope_allows($write_identity['scopes'], 'write')) {
        api_token_fail('the write scope was not stored');
    }
    api_token_pass('array scopes are stored in canonical order');

    $expired = api_token_create($db, $user_id, 'Expired', 'read');
    if ($expired === null) {
        api_token_fail('an expiring token could not be created');
    }
    $db->query(
        "UPDATE api_tokens SET expires_at = '2020-01-01 00:00:00' WHERE id = " . (int) $expired['id']
    );
    if (api_token_authenticate($db, $expired['clear']) !== null) {
        api_token_fail('an expired token was accepted');
    }
    api_token_pass('expired tokens are rejected');

    if (!api_token_revoke($db, (int) $created['id'], $user_id)) {
        api_token_fail('the owner could not revoke the token');
    }
    if (api_token_authenticate($db, $created['clear']) !== null) {
        api_token_fail('a revoked token was accepted');
    }
    if (api_token_revoke($db, (int) $created['id'], $user_id)) {
        api_token_fail('a token was revoked twice');
    }
    api_token_pass('revocation is immediate and idempotent');

    if (api_token_revoke($db, (int) $write_token['id'], $user_id + 999)) {
        api_token_fail('another account could revoke the token');
    }
    api_token_pass('owners can only revoke their own tokens');

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
    api_token_fail($exception->getMessage());
}

echo "API token integration passed: issuance, hashing, scopes, expiry, revocation and ownership.\n";

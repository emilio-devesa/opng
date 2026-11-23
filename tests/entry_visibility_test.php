<?php

declare(strict_types=1);

/**
 * Visibility, expiry and protection regression test (10.5).
 *
 * Exercises the pure access helpers and checks the entry points that must
 * enforce them. No database is required.
 */

require __DIR__ . '/../src/includes/entry_access.php';

function visibility_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function visibility_pass(string $message): void
{
    echo "PASS: {$message}\n";
}

function visibility_assert(bool $condition, string $message): void
{
    if (!$condition) {
        visibility_fail($message);
    }
    visibility_pass($message);
}

visibility_assert(entry_visibility_valid('public'), 'public is a valid visibility');
visibility_assert(entry_visibility_valid('unlisted'), 'unlisted is a valid visibility');
visibility_assert(entry_visibility_valid('private'), 'private is a valid visibility');
visibility_assert(!entry_visibility_valid('secret'), 'unknown visibilities are rejected');
visibility_assert(array_keys(entry_visibility_options()) === ['public', 'unlisted', 'private'], 'forms offer the three visibilities');

visibility_assert(entry_expiry_from_option('never') === null, 'never expiry stores NULL');
visibility_assert(
    entry_expiry_from_option('1h', 1000) === gmdate('Y-m-d H:i:s', 4600),
    'preset expiry options become absolute datetimes'
);
visibility_assert(entry_access_input([], true)['expiry'] === 'keep', 'omitting expiry on edit preserves it');
visibility_assert(entry_access_input(['expiry' => 'keep']) === 'error_expiry_invalid', 'keep is only valid on edit');

visibility_assert(entry_is_deleted(['deleted_at' => '2024-01-01 00:00:00']), 'deleted rows are detected');
visibility_assert(!entry_is_deleted(['deleted_at' => null]), 'live rows are not deleted');
visibility_assert(entry_is_expired(['expires_at' => '2020-01-01 00:00:00']), 'past expiries are expired');
visibility_assert(!entry_is_expired(['expires_at' => null]), 'NULL expiries never expire');
visibility_assert(entry_is_expired(['expires_at' => 'not-a-date']), 'malformed expiries fail closed');

$filter = entry_public_filter_sql('entries');
visibility_assert(
    str_contains($filter, 'entries.deleted_at IS NULL')
        && str_contains($filter, "entries.visibility = 'public'")
        && str_contains($filter, 'entries.expires_at > UTC_TIMESTAMP()'),
    'the public listing excludes deleted, expired and non-public rows'
);
$bad_alias_rejected = false;
try {
    entry_public_filter_sql('entries; DROP TABLE users');
} catch (InvalidArgumentException) {
    $bad_alias_rejected = true;
}
visibility_assert($bad_alias_rejected, 'the listing alias is validated');

$public = ['id' => 'a', 'user_id' => 1, 'visibility' => 'public'];
$unlisted = ['id' => 'b', 'user_id' => 1, 'visibility' => 'unlisted'];
$private = ['id' => 'c', 'user_id' => 1, 'visibility' => 'private'];
visibility_assert(entry_visibility_allows($public, null, 'guest'), 'guests can read public entries');
visibility_assert(entry_visibility_allows($unlisted, null, 'guest'), 'guests can read unlisted entries');
visibility_assert(!entry_visibility_allows($private, null, 'guest'), 'guests cannot read private entries');
visibility_assert(!entry_visibility_allows($private, 2, 'user'), 'other users cannot read private entries');
visibility_assert(entry_visibility_allows($private, 1, 'user'), 'the owner can read a private entry');
visibility_assert(entry_visibility_allows($private, 2, 'admin'), 'administrators can read private entries');

$protected = ['id' => 'd', 'protection_hash' => 'hash'];
visibility_assert(entry_requires_unlock($protected), 'protected entries require an unlock');
visibility_assert(!entry_requires_unlock(['protection_hash' => null]), 'unprotected entries do not require an unlock');

$_SESSION = [];
visibility_assert(!entry_is_unlocked('d'), 'threads start locked');
entry_mark_unlocked('d');
visibility_assert(entry_is_unlocked('d'), 'unlocking is stored per thread root');
$_SESSION['opng_unlocked']['d'] = time() - 1;
visibility_assert(!entry_is_unlocked('d'), 'expired unlocks are discarded');
visibility_assert(!isset($_SESSION['opng_unlocked']['d']), 'expired unlock entries are removed');
entry_mark_unlocked('d');
entry_clear_unlocked();
visibility_assert(!entry_is_unlocked('d'), 'clearing the session removes unlocks');

$parent = [
    'visibility' => 'private',
    'expires_at' => '2030-01-01 00:00:00',
    'protection_hash' => 'stored-hash',
];
$inherited = entry_reply_inherited_fields($parent);
visibility_assert(
    $inherited['visibility'] === 'private'
        && $inherited['expires_at'] === '2030-01-01 00:00:00'
        && $inherited['protection_hash'] === 'stored-hash',
    'replies inherit visibility, expiry and protection from the parent'
);
$inherited = entry_reply_inherited_fields(['visibility' => 'bogus']);
visibility_assert(
    $inherited['visibility'] === 'public'
        && $inherited['expires_at'] === null
        && $inherited['protection_hash'] === null,
    'invalid parent values fall back to a public, unprotected reply'
);

visibility_assert(entry_protection_error('') === null, 'an empty protection password disables protection');
visibility_assert(entry_protection_error('abcd') === null, 'four-character protection passwords are accepted');
visibility_assert(entry_protection_error('abc') === 'error_protection_password', 'short protection passwords are rejected');
visibility_assert(
    entry_protection_error(str_repeat('a', 73)) === 'error_protection_password',
    'overlong protection passwords are rejected'
);

$hash = entry_protection_hash('correct horse');
visibility_assert(entry_access_input([])['visibility'] === 'public', 'omitted visibility keeps the classic default');
foreach ([
    ['visibility' => null], ['visibility' => []], ['visibility' => str_repeat('x', 17)],
    ['expiry' => []], ['expiry' => 'invalid'], ['protection_password' => []],
    ['protection_password' => str_repeat('é', 37)], ['remove_protection' => ['yes']],
] as $bad) {
    visibility_assert(is_string(entry_access_input($bad)), 'malformed privacy fields return an error');
}
visibility_assert(
    entry_access_input(['protection_password' => str_repeat('é', 36)])['password'] === str_repeat('é', 36),
    'a valid 72-byte password is preserved verbatim'
);
visibility_assert(entry_protection_verify('correct horse', $hash), 'protection hashes verify the password');
visibility_assert(!entry_protection_verify('wrong horse', $hash), 'protection hashes reject wrong passwords');
if (defined('PASSWORD_ARGON2ID')) {
    $info = password_get_info($hash);
    visibility_assert(($info['algoName'] ?? '') === 'argon2id', 'protection passwords use Argon2id when available');
} else {
    visibility_pass('PASSWORD_ARGON2ID is unavailable; PASSWORD_DEFAULT is used');
}

$submit_source = (string) file_get_contents(__DIR__ . '/../src/submit.php');
visibility_assert(
    str_contains($submit_source, 'entry_reply_inherited_fields($parent)'),
    'submit.php copies access fields from the reply parent'
);
$update_source = (string) file_get_contents(__DIR__ . '/../src/update.php');
visibility_assert(
    str_contains($update_source, 'WHERE id = ? OR root_id = ?')
        && str_contains($update_source, '$is_root'),
    'update.php propagates a root edit to every entry in the thread'
);
visibility_assert(
    str_contains($update_source, 'UPDATE entries SET language = ?, text = ?, topic = ?, author_label = ?')
        && !str_contains($update_source, 'SET language = ?, text = ?, topic = ?, author_label = ?, visibility'),
    'editing a reply leaves its inherited access fields untouched'
);
$view_source = (string) file_get_contents(__DIR__ . '/../src/view.php');
visibility_assert(
    str_contains($view_source, 'entry_requires_unlock(') && str_contains($view_source, 'unlock.php'),
    'view.php enforces access and redirects to the unlock page'
);
visibility_assert(
    is_file(__DIR__ . '/../src/unlock.php'),
    'the unlock route exists'
);
$index_source = (string) file_get_contents(__DIR__ . '/../src/index.php');
$cursor_source = (string) file_get_contents(__DIR__ . '/../src/includes/pagination_cursor.php');
visibility_assert(
    str_contains($index_source, 'build_listing_query(')
        && str_contains($cursor_source, 'entry_public_filter_sql('),
    'the home listing uses the shared public filter through the cursor query'
);

echo "Visibility regression passed: access rules, inheritance, protection and route contracts.\n";

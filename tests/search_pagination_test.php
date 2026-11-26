<?php

require __DIR__ . '/../src/includes/topic_search.php';
require __DIR__ . '/../src/includes/pagination_cursor.php';
require __DIR__ . '/../src/includes/user_quota.php';

function assert_value(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

assert_value(
    '+hello* +world*',
    topic_search_expression('hello, world'),
    'full-text search requires each sanitized word and supports word prefixes'
);
assert_value(
    null,
    topic_search_expression(str_repeat('x', OPNG_MAX_SEARCH_QUERY_BYTES + 1)),
    'search query byte length is bounded'
);
assert_value(null, topic_search_expression('!@#$%'), 'punctuation-only searches are rejected');
assert_value(null, topic_search_expression("\xFF"), 'invalid UTF-8 search input is rejected');

$cursor = ['id' => 'seed0059', 'date' => '2025-11-02 12:30:45'];
$encoded_cursor = encode_entry_cursor($cursor);
assert_value($cursor, decode_entry_cursor($encoded_cursor), 'pagination cursors round-trip');
assert_value(
    null,
    decode_entry_cursor(encode_entry_cursor(['id' => 'legacy-id', 'date' => null])),
    'cursors without a date are rejected because entries.date is NOT NULL'
);
assert_value(
    null,
    decode_entry_cursor(encode_entry_cursor([
        'id' => str_repeat('中', 255),
        'date' => '0000-00-00 00:00:00',
    ])),
    'legacy zero dates are rejected after the schema migration'
);
assert_value(
    ['id' => str_repeat('中', 255), 'date' => '2025-11-02 12:30:45'],
    decode_entry_cursor(encode_entry_cursor([
        'id' => str_repeat('中', 255),
        'date' => '2025-11-02 12:30:45',
    ])),
    'cursors support the maximum utf8mb4 ID length'
);
assert_value(null, decode_entry_cursor('not-a-cursor'), 'malformed cursors are rejected');
assert_value(
    [
        'sql' => '(entries.date < ? OR (entries.date = ? AND entries.id < ?))',
        'params' => ['2025-11-02 12:30:45', '2025-11-02 12:30:45', 'seed0059'],
    ],
    entry_cursor_condition($cursor, 'older'),
    'older-page cursors use a stable date/id boundary'
);
$listing_cursor = [
    'sort' => 'topic',
    'order' => 'asc',
    'value' => 'Alpha',
    'id' => 'entry-1',
];
assert_value(
    $listing_cursor,
    decode_listing_cursor(encode_listing_cursor($listing_cursor)),
    'global listing cursors preserve sort values and direction'
);
assert_value(
    null,
    decode_listing_cursor($encoded_cursor),
    'entry cursors are no longer accepted as legacy listing cursors'
);
assert_value(
    [
        'sql' => '(entries.topic > ? OR (entries.topic = ? AND entries.id > ?))',
        'params' => ['Alpha', 'Alpha', 'entry-1'],
    ],
    listing_cursor_condition($listing_cursor, 'older'),
    'ascending sorted cursors advance by sort value and stable ID'
);
assert_value(
    [
        'sql' => '(entries.author_label < ? OR (entries.author_label = ? AND entries.id < ?))',
        'params' => ['Guest', 'Guest', 'entry-2'],
    ],
    listing_cursor_condition([
        'sort' => 'author',
        'order' => 'asc',
        'value' => 'Guest',
        'id' => 'entry-2',
    ], 'newer'),
    'reverse sorted cursors use the opposite comparison'
);
assert_value(
    'entries.author_label',
    entry_sort_expression('author'),
    'author sorting uses the denormalized entry label instead of a users JOIN expression'
);
assert_value(null, entry_sort_expression('id'), 'unsupported listing columns cannot become SQL');
assert_value(
    'lucia_dev',
    entry_listing_sort_value('author', ['author_label' => 'lucia_dev']),
    'entries sort by their stored author label'
);
assert_value(
    'Guest',
    entry_listing_sort_value('author', ['author_label' => 'Guest']),
    'anonymous entries sort by their displayed author label'
);

$default_listing = build_listing_query(null, null, null, 'date', 'desc');
assert_value(
    true,
    str_contains($default_listing['sql'], 'ORDER BY entries.date desc, entries.id desc LIMIT 21'),
    'the default listing orders by the bare date column so the cursor index is usable'
);
assert_value(
    false,
    str_contains($default_listing['sql'], 'COALESCE(entries.date'),
    'the default listing no longer wraps the date in COALESCE'
);
assert_value([], $default_listing['params'], 'the default listing has no bound parameters');
assert_value(
    false,
    str_contains($default_listing['sql'], 'LEFT JOIN users'),
    'the global listing no longer joins users because the author label is denormalized'
);

$filtered_listing = build_listing_query('+needle*', [
    'sort' => 'topic',
    'order' => 'asc',
    'value' => 'Alpha',
    'id' => 'entry-1',
], 'older', 'topic', 'asc');
assert_value(
    ['+needle*', 'Alpha', 'Alpha', 'entry-1'],
    $filtered_listing['params'],
    'the shared listing builder keeps search and cursor parameters in order'
);
assert_value(
    true,
    str_contains($filtered_listing['sql'], 'MATCH(entries.topic) AGAINST (? IN BOOLEAN MODE)'),
    'the shared listing builder includes the FULLTEXT search clause'
);

$user_listing = build_listing_query(null, null, null, 'date', 'desc', 42, 'user');
assert_value(
    [42],
    $user_listing['params'],
    'a signed-in listing binds the viewer id for its own private entries'
);
assert_value(
    true,
    str_contains($user_listing['sql'], "entries.visibility = 'private' AND entries.user_id = ?"),
    'a signed-in listing includes the viewer private entries'
);
$admin_listing = build_listing_query(null, null, null, 'date', 'desc', 7, 'admin');
assert_value([], $admin_listing['params'], 'an administrator listing has no visibility parameter');
assert_value(
    false,
    str_contains($admin_listing['sql'], 'entries.visibility ='),
    'an administrator listing does not restrict visibility'
);

assert_value(
    [
        'sql' => '(entries.date < ? OR (entries.date = ? AND entries.id < ?))',
        'params' => ['2025-11-02 12:30:45', '2025-11-02 12:30:45', 'seed0059'],
    ],
    admin_keyset_condition('entries.date', 'entries.id', [
        'id' => 'seed0059',
        'date' => '2025-11-02 12:30:45',
    ], 'older'),
    'admin entry keyset pagination uses a stable date/id boundary'
);
assert_value(
    [
        'sql' => '(users.created_at > ? OR (users.created_at = ? AND users.id > ?))',
        'params' => ['2025-11-02 12:30:45', '2025-11-02 12:30:45', '42'],
    ],
    admin_keyset_condition('users.created_at', 'users.id', [
        'id' => '42',
        'date' => '2025-11-02 12:30:45',
    ], 'newer'),
    'admin user keyset pagination can move back to newer rows'
);

$under_quota = ['role' => 'user', 'used_bytes' => OPNG_USER_QUOTA_BYTES - 100];
assert_value(true, user_quota_allows($under_quota, 0, 100), 'a write exactly at the account quota is allowed');
assert_value(false, user_quota_allows($under_quota, 0, 101), 'a write over the account quota is rejected');
assert_value(
    true,
    user_quota_allows(['role' => 'admin', 'used_bytes' => 0], 0, OPNG_USER_QUOTA_BYTES + 1),
    'administrators are exempt from the account quota'
);
assert_value(
    false,
    user_quota_allows(['role' => 'user', 'used_bytes' => OPNG_USER_QUOTA_BYTES + 1], 0, 1),
    'over-quota accounts cannot increase stored bytes'
);
assert_value(
    true,
    user_quota_allows(['role' => 'user', 'used_bytes' => OPNG_USER_QUOTA_BYTES + 1], 10, 9),
    'over-quota accounts may reduce stored bytes'
);

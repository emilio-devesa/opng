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
    ['id' => 'legacy-id', 'date' => null],
    decode_entry_cursor(encode_entry_cursor(['id' => 'legacy-id', 'date' => null])),
    'cursors support entries with a null date'
);
assert_value(
    ['id' => str_repeat('中', 255), 'date' => '0000-00-00 00:00:00'],
    decode_entry_cursor(encode_entry_cursor([
        'id' => str_repeat('中', 255),
        'date' => '0000-00-00 00:00:00',
    ])),
    'cursors support the maximum utf8mb4 ID length and legacy zero dates'
);
assert_value(null, decode_entry_cursor('not-a-cursor'), 'malformed cursors are rejected');
assert_value(
    [
        'sql' => '(entries.date < ? OR entries.date IS NULL OR (entries.date = ? AND entries.id < ?))',
        'params' => ['2025-11-02 12:30:45', '2025-11-02 12:30:45', 'seed0059'],
    ],
    entry_cursor_condition($cursor, 'older'),
    'older-page cursors use a stable date/id boundary'
);
assert_value(
    [
        'sql' => '(entries.date IS NULL AND entries.id < ?)',
        'params' => ['legacy-id'],
    ],
    entry_cursor_condition(['id' => 'legacy-id', 'date' => null], 'older'),
    'older-page cursors handle null dates'
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
    [
        'sql' => "(COALESCE(entries.topic, '') > ? OR (COALESCE(entries.topic, '') = ? AND entries.id > ?))",
        'params' => ['Alpha', 'Alpha', 'entry-1'],
    ],
    listing_cursor_condition($listing_cursor, 'older'),
    'ascending sorted cursors advance by sort value and stable ID'
);
assert_value(
    [
        'sql' => "(COALESCE(users.username, 'Guest') < ? OR (COALESCE(users.username, 'Guest') = ? AND entries.id < ?))",
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
assert_value(null, entry_sort_expression('id'), 'unsupported listing columns cannot become SQL');
assert_value(
    'Guest',
    entry_listing_sort_value('author', ['username' => null]),
    'anonymous entries sort by their displayed author label'
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

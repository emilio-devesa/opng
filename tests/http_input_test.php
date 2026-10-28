<?php

require __DIR__ . '/../src/includes/http_input.php';

function assert_http_input(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

assert_http_input(
    'normalized',
    http_string_parameter(['value' => ' normalized '], 'value', 20),
    'string parameters are trimmed when requested'
);
assert_http_input(
    ' raw ',
    http_string_parameter(['value' => ' raw '], 'value', 5, false),
    'string parameters can preserve whitespace for secrets'
);
assert_http_input(
    null,
    http_string_parameter(['value' => ['array']], 'value', 20),
    'array parameters are rejected instead of coerced'
);
assert_http_input(
    null,
    http_string_parameter(['value' => str_repeat('é', 3)], 'value', 5),
    'string limits are measured in bytes'
);
assert_http_input(null, http_string_parameter([], 'value', 10), 'missing parameters are rejected');
assert_http_input(42, http_positive_integer('42'), 'positive integer strings are parsed');
assert_http_input(null, http_positive_integer(['42']), 'integer arrays are rejected');
assert_http_input(null, http_positive_integer('0'), 'zero is not a valid account ID');
assert_http_input(null, http_positive_integer('99999999999'), 'overflowing integer strings are rejected');
assert_http_input(true, http_valid_entry_id('a1B2'), 'alphanumeric entry IDs are accepted');
assert_http_input(false, http_valid_entry_id(str_repeat('a', 256)), 'oversized entry IDs are rejected');
assert_http_input(false, http_valid_entry_id('id-with-hyphen'), 'entry IDs with unsupported characters are rejected');

<?php

require __DIR__ . '/../src/includes/paste_validation.php';
require __DIR__ . '/../src/includes/password_validation.php';

function assert_same_value(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

$one_mebibyte = str_repeat('a', OPNG_MAX_PASTE_BYTES);
assert_same_value(null, paste_validation_error($one_mebibyte, 'topic'), 'a 1 MiB paste is accepted');
assert_same_value(
    'The text cannot exceed 1 MiB.',
    paste_validation_error($one_mebibyte . 'a', 'topic'),
    'a paste over 1 MiB is rejected'
);
assert_same_value(
    null,
    paste_validation_error('text', str_repeat('a', OPNG_MAX_TOPIC_BYTES)),
    'a 255-byte topic is accepted'
);
assert_same_value(
    'The topic cannot exceed 255 bytes.',
    paste_validation_error('text', str_repeat('a', OPNG_MAX_TOPIC_BYTES + 1)),
    'a 256-byte topic is rejected'
);
assert_same_value(
    'The topic cannot exceed 255 bytes.',
    paste_validation_error('text', str_repeat('é', 128)),
    'topic limits count UTF-8 bytes'
);
assert_same_value(
    'The text cannot be empty.',
    paste_validation_error(" \n\t", 'topic'),
    'whitespace-only paste content is rejected'
);
assert_same_value(
    null,
    password_validation_error(str_repeat('a', 12)),
    'a 12-byte password is accepted'
);
assert_same_value(
    null,
    password_validation_error(str_repeat('a', 72)),
    'a 72-byte bcrypt password is accepted'
);
assert_same_value(
    'Choose a password between 12 and 72 bytes long.',
    password_validation_error(str_repeat('a', 73)),
    "a password over bcrypt's 72-byte limit is rejected"
);

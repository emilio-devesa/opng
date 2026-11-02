<?php

require __DIR__ . '/../src/includes/error_messages.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$page = file_get_contents($root . '/src/auth/change_password.php');
assert_true(is_string($page), 'change-password page exists');

foreach ([
    'csrf_valid',
    'rate_limit_hit',
    'password_validation_error',
    'session_regenerate_id(true)',
    'reset_token = NULL',
    'reset_token_expires = NULL',
    'name="current_password"',
    'name="new_password"',
    'name="confirm_password"',
    'data-i18n="password_changed"',
] as $marker) {
    assert_true(is_string($page) && str_contains($page, $marker), "change-password page contains {$marker}");
}

$layout = file_get_contents($root . '/src/includes/layout_header.php');
assert_true(
    is_string($layout) && str_contains($layout, 'auth/change_password.php'),
    'account navigation links to the change-password page'
);

assert_true(
    opng_error_status('error_password_rate_limit') === 429,
    'password-change rate limiting returns HTTP 429'
);
foreach (['password_incorrect', 'password_mismatch', 'password_unchanged'] as $key) {
    assert_true(opng_error_key($key) === $key, "{$key} is a known inline error key");
}

$languages = ['en', 'es', 'de', 'fr', 'pt', 'zh'];
$keys = [
    'change_password_title',
    'current_password',
    'confirm_password',
    'password_changed',
    'password_incorrect',
    'password_mismatch',
    'password_unchanged',
    'error_password_rate_limit',
];
foreach ($languages as $language) {
    $catalog = json_decode(
        (string) file_get_contents($root . '/src/assets/lang/' . $language . '.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    foreach ($keys as $key) {
        assert_true(
            is_array($catalog) && isset($catalog[$key]) && trim((string) $catalog[$key]) !== '',
            "{$language}.json contains {$key}"
        );
    }
}

echo "Change-password contracts passed.\n";

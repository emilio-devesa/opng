<?php

require __DIR__ . '/../src/includes/ratelimit.php';
require __DIR__ . '/../src/includes/error_messages.php';

$failures = [];
$root = dirname(__DIR__);

if (
    RateLimitResult::Allowed === RateLimitResult::Limited
    || RateLimitResult::Allowed === RateLimitResult::Unavailable
    || RateLimitResult::Limited === RateLimitResult::Unavailable
) {
    $failures[] = 'Rate-limit outcomes must be distinct';
}

if (opng_error_status('error_service_unavailable') !== 503) {
    $failures[] = 'A rate-limit database failure must map to HTTP 503';
}
if (opng_error_status('error_login_rate_limit') !== 429) {
    $failures[] = 'A login limit must still map to HTTP 429';
}

if (!function_exists('rate_limit_status') || !function_exists('rate_limit_clear')) {
    $failures[] = 'Rate limiting must expose a read-only status check and a clear operation';
}

$login = file_get_contents($root . '/src/auth/login.php');
if (
    !is_string($login)
    || !str_contains($login, "'login_ip'")
    || !str_contains($login, "'login_account'")
    || !str_contains($login, '$captcha_required')
    || !str_contains($login, 'RateLimitResult::Unavailable')
) {
    $failures[] = 'Login is missing the IP/account limits, adaptive CAPTCHA, or 503 handling';
}
if (
    !is_string($login)
    || !str_contains($login, 'rate_limit_status')
    || !str_contains($login, 'rate_limit_clear')
    || strpos($login, 'rate_limit_status') === false
    || strpos($login, 'password_verify') === false
    || strpos($login, 'rate_limit_status') > strpos($login, 'password_verify')
) {
    $failures[] = 'Login must check failed-attempt budgets before bcrypt and clear them on success';
}
foreach (['login', 'login_account'] as $cleared_action) {
    if (!is_string($login) || !str_contains($login, "rate_limit_clear(\$db, '{$cleared_action}'")) {
        $failures[] = "A successful login must clear the '{$cleared_action}' bucket";
    }
}

$submit = file_get_contents($root . '/src/submit.php');
if (
    !is_string($submit)
    || !str_contains($submit, "'submit_guest'")
    || !str_contains($submit, "'submit_user'")
    || !str_contains($submit, 'global_quota_allows')
    || !str_contains($submit, 'captcha_incorrect')
    || !str_contains($submit, 'RateLimitResult::Unavailable')
) {
    $failures[] = 'Paste creation is missing guest CAPTCHA, split limits, the global quota, or 503 handling';
}

foreach ([
    '/src/auth/register.php',
    '/src/auth/request_reset.php',
] as $path) {
    $source = file_get_contents($root . $path);
    if (!is_string($source) || !str_contains($source, 'RateLimitResult::Unavailable')) {
        $failures[] = "Sensitive action does not distinguish rate-limit DB failures: {$path}";
    }
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Rate-limit contracts passed: distinct outcomes, 503 mapping, login limits, guest CAPTCHA, and global quota.\n";

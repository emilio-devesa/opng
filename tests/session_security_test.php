<?php

// Buffer the PASS messages so a second session can still change its cookie
// parameters; otherwise the first echo would mark the headers as sent.
ob_start();

require __DIR__ . '/../src/includes/session.php';
require __DIR__ . '/../src/includes/csrf.php';

$_SERVER['HTTPS'] = 'off';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
session_start_secured();

if (session_get_cookie_params()['secure'] !== false) {
    fwrite(STDERR, "FAIL: an untrusted forwarded header enabled the Secure cookie flag\n");
    exit(1);
}
echo "PASS: untrusted X-Forwarded-Proto does not enable HTTPS cookies\n";

$_SESSION['csrf_token'] = str_repeat('a', 64);
if (csrf_valid(['array'])) {
    fwrite(STDERR, "FAIL: array-valued CSRF tokens must be rejected\n");
    exit(1);
}
echo "PASS: malformed CSRF token types are rejected\n";

session_destroy();

putenv('OPNG_FORCE_HTTPS=1');
$_SERVER['HTTPS'] = 'off';
$_SERVER['SERVER_PORT'] = '80';
session_start_secured();

if (session_get_cookie_params()['secure'] !== true) {
    fwrite(STDERR, "FAIL: OPNG_FORCE_HTTPS did not enable the Secure cookie flag\n");
    exit(1);
}
echo "PASS: OPNG_FORCE_HTTPS enables the Secure cookie flag\n";

if (session_name() !== '__Host-opng_session') {
    fwrite(STDERR, "FAIL: HTTPS sessions do not use the __Host- cookie prefix\n");
    exit(1);
}
echo "PASS: HTTPS sessions use the __Host- cookie prefix\n";

if (ini_get('session.sid_length') !== '48' || ini_get('session.sid_bits_per_character') !== '6') {
    fwrite(STDERR, "FAIL: the session ID entropy settings were not raised\n");
    exit(1);
}
echo "PASS: session ID length and entropy are raised\n";

$_SESSION['csrf_token'] = str_repeat('a', 64);
csrf_rotate();
if (
    !isset($_SESSION['csrf_token'])
    || !is_string($_SESSION['csrf_token'])
    || $_SESSION['csrf_token'] === str_repeat('a', 64)
    || strlen($_SESSION['csrf_token']) !== 64
) {
    fwrite(STDERR, "FAIL: csrf_rotate() did not replace the token\n");
    exit(1);
}
echo "PASS: csrf_rotate() replaces the token after a privilege change\n";

if (ini_get('session.use_strict_mode') !== '1') {
    fwrite(STDERR, "FAIL: session.use_strict_mode is not enabled\n");
    exit(1);
}
echo "PASS: session.use_strict_mode is enabled\n";

if (!isset($_SESSION['opng_created_at'], $_SESSION['opng_last_activity'])) {
    fwrite(STDERR, "FAIL: session timestamps are missing\n");
    exit(1);
}
echo "PASS: idle and absolute session timestamps are recorded\n";

$_SESSION['user_id'] = 7;
$_SESSION['username'] = 'someone';
$_SESSION['role'] = 'admin';
$_SESSION['session_version'] = 3;
session_forget_account();
if (isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['session_version'])) {
    fwrite(STDERR, "FAIL: session_forget_account() kept authenticated identity keys\n");
    exit(1);
}
echo "PASS: session_forget_account() drops the authenticated identity\n";

$session_source = file_get_contents(__DIR__ . '/../src/includes/session.php');
$db_source = file_get_contents(__DIR__ . '/../src/includes/db.php');
$login_source = file_get_contents(__DIR__ . '/../src/auth/login.php');
$change_source = file_get_contents(__DIR__ . '/../src/auth/change_password.php');
$reset_source = file_get_contents(__DIR__ . '/../src/auth/reset_password.php');
foreach ([
    'session.php defines session_validate_account' => is_string($session_source) && str_contains($session_source, 'function session_validate_account'),
    'db_connect validates the session version' => is_string($db_source) && str_contains($db_source, 'session_validate_account($conn)'),
    'login stores the account session version' => is_string($login_source) && str_contains($login_source, 'session_version'),
    'password changes increment the session version' => is_string($change_source) && str_contains($change_source, 'session_version = session_version + 1'),
    'password resets increment the session version' => is_string($reset_source) && str_contains($reset_source, 'session_version = session_version + 1'),
    'login rotates the CSRF token after authentication' => is_string($login_source) && str_contains($login_source, 'csrf_rotate()'),
    'password changes rotate the CSRF token' => is_string($change_source) && str_contains($change_source, 'csrf_rotate()'),
    'password resets rotate the CSRF token' => is_string($reset_source) && str_contains($reset_source, 'csrf_rotate()'),
] as $message => $condition) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

session_destroy();
putenv('OPNG_FORCE_HTTPS');

ob_end_flush();

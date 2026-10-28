<?php

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

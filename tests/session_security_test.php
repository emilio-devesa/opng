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

session_destroy();
putenv('OPNG_FORCE_HTTPS');

ob_end_flush();

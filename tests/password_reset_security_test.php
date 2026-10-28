<?php
require __DIR__ . '/../src/includes/password_reset.php';

if (in_array($argv[1] ?? '', ['--route=request', '--route=reset'], true)) {
    putenv('OPNG_DEV_RESET');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
    $_SERVER['HTTP_HOST'] = 'public.example.test';
    $_SERVER['REQUEST_METHOD'] = 'GET';

    if ($argv[1] === '--route=request') {
        require __DIR__ . '/../src/auth/request_reset.php';
        exit(0);
    }

    $_GET['token'] = str_repeat('b', 64);
    require __DIR__ . '/../src/auth/reset_password.php';
    exit(0);
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }

    echo "PASS: $message\n";
}

function assert_public_route_disabled(string $route, string $forbidden_content, string $message): void
{
    $command = [PHP_BINARY, __FILE__, '--route=' . $route];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, __DIR__ . '/../src/auth');
    if (!is_resource($process)) {
        fwrite(STDERR, "FAIL: could not start route security probe\n");
        exit(1);
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error_output = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);

    if ($exit_code !== 0 || !str_contains($output, 'Password resets are unavailable') ||
        str_contains($output, '<form') || str_contains($output, $forbidden_content)) {
        fwrite(STDERR, "FAIL: $message (exit $exit_code)\n$output\n$error_output\n");
        exit(1);
    }

    echo "PASS: $message\n";
}

putenv('OPNG_DEV_RESET');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost:8888';
assert_same(false, password_reset_simulation_enabled(), 'simulation is disabled by default');

putenv('OPNG_DEV_RESET=1');
assert_same(true, password_reset_simulation_enabled(), 'explicit local IPv4 simulation is allowed');

$_SERVER['REMOTE_ADDR'] = '::1';
$_SERVER['HTTP_HOST'] = '[::1]:8888';
assert_same(true, password_reset_simulation_enabled(), 'explicit local IPv6 simulation is allowed');

$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
assert_same(false, password_reset_simulation_enabled(), 'remote clients cannot access the simulation');

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost.attacker.example';
assert_same(false, password_reset_simulation_enabled(), 'lookalike hostnames cannot access the simulation');

$_SERVER['HTTP_HOST'] = 'localhost';
putenv('OPNG_DEV_RESET=0');
assert_same(false, password_reset_simulation_enabled(), 'any flag other than 1 disables the simulation');

putenv('OPNG_DEV_RESET=1');
$_SERVER['REMOTE_ADDR'] = ['127.0.0.1'];
assert_same(false, password_reset_simulation_enabled(), 'malformed server address data disables the simulation');

$token = str_repeat('a', 64);
$token_hash = password_reset_token_hash($token);
assert_same(hash('sha256', $token), $token_hash, 'valid reset tokens are stored as SHA-256 hashes');
assert_same(null, password_reset_token_hash('not-a-token'), 'malformed reset tokens are rejected');

putenv('OPNG_DEV_RESET');
assert_public_route_disabled(
    'request',
    'reset_password.php?token=',
    'public reset requests show neither a form nor a simulated email link'
);
assert_public_route_disabled(
    'reset',
    str_repeat('b', 64),
    'public reset links cannot reveal a password form or reflect the supplied token'
);

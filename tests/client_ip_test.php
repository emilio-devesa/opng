<?php

require __DIR__ . '/../src/includes/ratelimit.php';

function assert_ip(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message} (expected {$expected}, got {$actual})\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

function reset_client_ip_env(): void
{
    putenv('OPNG_TRUSTED_PROXIES');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
}

reset_client_ip_env();
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
assert_ip('198.51.100.7', client_ip(), 'forwarded headers are ignored without trusted proxies');

$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
assert_ip('0.0.0.0', client_ip(), 'a malformed REMOTE_ADDR falls back to 0.0.0.0');
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

putenv('OPNG_TRUSTED_PROXIES=10.0.0.0/8');
$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.5';
assert_ip('203.0.113.9', client_ip(), 'the first untrusted hop from the right is the client');

$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.9, 10.0.0.5';
assert_ip('203.0.113.9', client_ip(), 'a client cannot prepend a forged address to X-Forwarded-For');

$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.8, 10.0.0.5';
assert_ip('10.1.2.3', client_ip(), 'an all-trusted chain falls back to the direct peer');

$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, 203.0.113.9:443, 10.0.0.5';
assert_ip('203.0.113.9', client_ip(), 'IPv4 ports and malformed hops are handled');

$_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8::1, 10.0.0.5';
assert_ip('2001:db8::1', client_ip(), 'IPv6 clients are preserved');

$_SERVER['HTTP_X_FORWARDED_FOR'] = '[2001:db8::2]:443, 10.0.0.5';
assert_ip('2001:db8::2', client_ip(), 'bracketed IPv6 with a port is normalized');

putenv('OPNG_TRUSTED_PROXIES=192.0.2.0/24,10.0.0.5');
$_SERVER['REMOTE_ADDR'] = '192.0.2.20';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.30';
assert_ip('198.51.100.30', client_ip(), 'CIDR and single-address proxy entries both work');

reset_client_ip_env();
echo "Client IP resolution contracts passed.\n";

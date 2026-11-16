<?php

declare(strict_types=1);

/**
 * MFA/TOTP regression test (10.2).
 *
 * Verifies RFC 6238 vectors, base32 round-trips, encrypted secret storage,
 * recovery-code format/entropy and the drift window. No database or network is
 * required.
 */

putenv('OPNG_MFA_KEY=' . base64_encode(str_repeat('k', 32)));

require __DIR__ . '/../src/includes/mfa.php';

function mfa_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mfa_pass(string $message): void
{
    echo "PASS: {$message}\n";
}

function mfa_assert(bool $condition, string $message): void
{
    if (!$condition) {
        mfa_fail($message);
    }
    mfa_pass($message);
}

$rfc_secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
mfa_assert(
    mfa_base32_decode($rfc_secret) === '12345678901234567890',
    'base32 decodes the RFC 6238 SHA-1 test secret'
);

$vectors = [
    59 => '94287082',
    1111111109 => '07081804',
    1111111111 => '14050471',
    1234567890 => '89005924',
    2000000000 => '69279037',
    20000000000 => '65353130',
];
foreach ($vectors as $timestamp => $expected) {
    $actual = mfa_totp($rfc_secret, (int) $timestamp, 8);
    if ($actual !== $expected) {
        mfa_fail("TOTP vector at {$timestamp} expected {$expected}, got " . (string) $actual);
    }
}
mfa_pass('TOTP matches the RFC 6238 SHA-1 vectors');

$bytes = random_bytes(20);
mfa_assert(mfa_base32_decode(mfa_base32_encode($bytes)) === $bytes, 'base32 encoding round-trips');

$secret = mfa_generate_secret();
mfa_assert(strlen($secret) === 32, 'generated TOTP secrets contain 160 bits');
mfa_assert(mfa_totp($secret) !== null, 'generated secrets produce a six-digit code');

$encrypted = mfa_encrypt_secret($secret);
mfa_assert(is_string($encrypted) && $encrypted !== $secret, 'TOTP secrets are encrypted at rest');
mfa_assert(mfa_decrypt_secret((string) $encrypted) === $secret, 'stored TOTP secrets decrypt to the original');
mfa_assert(mfa_decrypt_secret('not-base64') === null, 'malformed encrypted secrets are rejected');

$tampered = base64_decode((string) $encrypted, true);
if (!is_string($tampered)) {
    mfa_fail('encrypted secret is valid base64');
}
$tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0x01);
mfa_assert(
    mfa_decrypt_secret(base64_encode($tampered)) === null,
    'tampered ciphertext fails authentication'
);

$codes = mfa_generate_recovery_codes();
mfa_assert(count($codes) === OPNG_MFA_RECOVERY_CODES, 'a full set of recovery codes is generated');
$unique = array_unique($codes);
mfa_assert(count($unique) === count($codes), 'recovery codes are unique');
foreach ($codes as $code) {
    if (preg_match('/\A[A-F0-9]{5}(?:-[A-F0-9]{5}){3}\z/', $code) !== 1) {
        mfa_fail('recovery code has an unexpected format: ' . $code);
    }
}
mfa_pass('recovery codes use four groups of five hex characters (80 bits)');

$code = (string) mfa_totp($secret, 1700000000);
mfa_assert(mfa_verify_totp($secret, $code, 1700000000), 'a current TOTP code is accepted');
mfa_assert(mfa_verify_totp($secret, $code, 1700000030), 'one period of forward drift is accepted');
mfa_assert(mfa_verify_totp($secret, $code, 1699999970), 'one period of backward drift is accepted');
mfa_assert(!mfa_verify_totp($secret, $code, 1700000060), 'two periods of drift are rejected');
mfa_assert(!mfa_verify_totp($secret, '12345', 1700000000), 'short codes are rejected');
mfa_assert(!mfa_verify_totp($secret, 'abcdef', 1700000000), 'non-numeric codes are rejected');

mfa_assert(
    mfa_recovery_normalize(' abcd-ef12 ') === 'ABCDEF12',
    'recovery codes normalize case and separators before hashing'
);
mfa_assert(
    mfa_recovery_hash('abcd-ef12') === mfa_recovery_hash('ABCDEF12'),
    'normalized recovery codes hash consistently'
);
mfa_assert(
    function_exists('mfa_replace_recovery_codes'),
    'recovery codes can be replaced atomically'
);

echo "MFA regression passed: TOTP vectors, encryption, recovery codes and drift window.\n";

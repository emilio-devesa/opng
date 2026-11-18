<?php

/**
 * Keyed hashing contracts (SEC-21).
 *
 * The audit log and rate limiter must keep storing stable, non-reversible
 * identifiers. When OPNG_HASH_KEY is present the digest must be HMAC-SHA-256;
 * without it the legacy plain SHA-256 fallback keeps old rows usable.
 */

require __DIR__ . '/../src/includes/hashing.php';

$failures = [];

function expect_hashing(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

putenv('OPNG_HASH_KEY');
expect_hashing(
    opng_hash_key() === null,
    'a missing key selects the backward-compatible fallback',
    $failures
);
expect_hashing(
    opng_hash_identifier('203.0.113.7') === hash('sha256', '203.0.113.7'),
    'without a key the digest is a plain SHA-256',
    $failures
);

$base64_key = base64_encode(str_repeat('k', 32));
putenv('OPNG_HASH_KEY=' . $base64_key);
expect_hashing(opng_hash_key() === str_repeat('k', 32), 'a base64 32-byte key is decoded', $failures);
expect_hashing(
    opng_hash_identifier('203.0.113.7') === hash_hmac('sha256', '203.0.113.7', str_repeat('k', 32)),
    'a configured key switches identifiers to HMAC-SHA-256',
    $failures
);
expect_hashing(
    opng_hash_identifier('203.0.113.7') !== hash('sha256', '203.0.113.7'),
    'the keyed digest differs from the unkeyed one',
    $failures
);
expect_hashing(
    opng_hash_identifier('203.0.113.7') === opng_hash_identifier('203.0.113.7'),
    'keyed identifiers stay stable across calls',
    $failures
);

$raw_key = str_repeat('r', 32);
putenv('OPNG_HASH_KEY=' . $raw_key);
expect_hashing(opng_hash_key() === $raw_key, 'a raw 32-byte key is accepted', $failures);

putenv('OPNG_HASH_KEY=too-short');
expect_hashing(opng_hash_key() === null, 'short key material is rejected', $failures);
expect_hashing(
    opng_hash_identifier('value') === hash('sha256', 'value'),
    'invalid key material falls back to plain SHA-256',
    $failures
);
expect_hashing(
    opng_hash_decode_key(base64_encode(str_repeat('x', 16))) === null,
    'base64 that decodes to fewer than 32 bytes is rejected',
    $failures
);
expect_hashing(opng_hash_decode_key('') === null, 'an empty key is rejected', $failures);
expect_hashing(opng_hash_decode_key(null) === null, 'a non-string key is rejected', $failures);

putenv('OPNG_HASH_KEY');

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Hashing contracts passed: fallback, base64/raw keys and HMAC-SHA-256.\n";

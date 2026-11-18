<?php

declare(strict_types=1);

// includes/hashing.php
// Keyed hashing for pseudonymous identifiers (SEC-21).
//
// Audit targets, client IP addresses and rate-limit keys are stored as digests,
// never in clear text. A plain SHA-256 of a low-entropy value (an IPv4 address
// or an email address) can be reversed with a precomputed table, so when
// OPNG_HASH_KEY is configured we derive the digest with HMAC-SHA-256 instead.
//
// OPNG_HASH_KEY accepts either 32 or more raw bytes, or a base64 value that
// decodes to at least 32 bytes. The plain SHA-256 fallback keeps existing audit
// rows and rate-limit buckets working during an upgrade and lets a development
// install run without a secret; tools/doctor.php warns while it is active.

/**
 * Decode key material from a raw configuration value. Returns null when the
 * value is empty, shorter than 32 bytes and not valid base64, or decodes to
 * fewer than 32 bytes.
 */
function opng_hash_decode_key(mixed $raw): ?string
{
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $raw = trim($raw);

    $decoded = base64_decode($raw, true);
    if (is_string($decoded) && strlen($decoded) >= 32) {
        return $decoded;
    }

    if (strlen($raw) >= 32) {
        return $raw;
    }

    return null;
}

/**
 * Decode the configured hashing key. Returns null when OPNG_HASH_KEY is
 * missing or does not provide at least 32 bytes of key material.
 */
function opng_hash_key(): ?string
{
    return opng_hash_decode_key(getenv('OPNG_HASH_KEY'));
}

/**
 * Derive the stable digest used for a pseudonymous identifier.
 *
 * The same input and key always produce the same value, so audit entries and
 * rate-limit counters keep working across requests and across a restart.
 */
function opng_hash_identifier(string $value): string
{
    $key = opng_hash_key();
    if ($key === null) {
        return hash('sha256', $value);
    }

    return hash_hmac('sha256', $value, $key);
}

<?php

declare(strict_types=1);

// includes/healthz.php
// Access control for the optional health-check detail (SEC-26).
//
// The public endpoint only reports {"status":"ok"}. An operator who wants the
// schema version for monitoring sets OPNG_HEALTHZ_DETAIL_TOKEN and sends it as
// an Authorization: Bearer token. The token is compared in constant time.

/**
 * Whether the request may receive the detailed health payload.
 */
function healthz_detail_authorized(?string $configured_token, ?string $authorization_header): bool
{
    if (
        !is_string($configured_token)
        || $configured_token === ''
        || !is_string($authorization_header)
    ) {
        return false;
    }

    if (preg_match('/\ABearer[ \t]+(\S+)\z/i', trim($authorization_header), $matches) !== 1) {
        return false;
    }

    return hash_equals($configured_token, $matches[1]);
}

/**
 * Read the Authorization header from the server variables.
 *
 * @param array<string, mixed> $server
 */
function healthz_authorization_header(array $server): ?string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (is_string($server[$key] ?? null) && $server[$key] !== '') {
            return $server[$key];
        }
    }

    return null;
}

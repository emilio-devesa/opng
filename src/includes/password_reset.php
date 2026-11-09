<?php

declare(strict_types=1);

function password_reset_simulation_enabled(): bool
{
    if (getenv('OPNG_DEV_RESET') !== '1') {
        return false;
    }

    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
    $request_host = $_SERVER['HTTP_HOST'] ?? '';
    if (!is_string($remote_addr) || !is_string($request_host)) {
        return false;
    }

    $host = parse_url('http://' . $request_host, PHP_URL_HOST);

    return in_array($remote_addr, ['127.0.0.1', '::1'], true)
        && is_string($host)
        && in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
}

function password_reset_token_hash(string $token): ?string
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        return null;
    }

    return hash('sha256', $token);
}

// The reset link is exchanged for this short-lived session record so the token
// disappears from the address bar, history and Referer (SEC-08).
const OPNG_RESET_SESSION_TTL = 600;

/**
 * Store the validated token hash for the current browser session.
 */
function password_reset_session_store(string $token_hash): void
{
    $_SESSION['opng_reset'] = [
        'token_hash' => $token_hash,
        'expires' => time() + OPNG_RESET_SESSION_TTL,
    ];
}

/**
 * @return array{token_hash: string, expires: int}|null
 */
function password_reset_session_get(): ?array
{
    $reset = $_SESSION['opng_reset'] ?? null;
    if (!is_array($reset)) {
        return null;
    }

    $token_hash = $reset['token_hash'] ?? null;
    $expires = $reset['expires'] ?? null;
    if (!is_string($token_hash) || !is_int($expires) || $expires < time()) {
        password_reset_session_clear();
        return null;
    }

    return ['token_hash' => $token_hash, 'expires' => $expires];
}

function password_reset_session_clear(): void
{
    unset($_SESSION['opng_reset']);
}

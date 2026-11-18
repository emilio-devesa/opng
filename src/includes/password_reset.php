<?php

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/i18n.php';

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

/**
 * Whether this installation can deliver a reset link to the account owner.
 * The local development simulation counts as available because it renders the
 * link on localhost only.
 */
function password_reset_available(): bool
{
    if (password_reset_simulation_enabled()) {
        return true;
    }

    return opng_mail_is_deliverable() && opng_mail_validate_config() === [];
}

/**
 * Absolute base URL used in recovery mail. OPNG_PUBLIC_URL is authoritative;
 * otherwise the current request scheme/host is used (safe because the link is
 * only ever sent to the account owner).
 */
function password_reset_public_base_url(): string
{
    $configured = getenv('OPNG_PUBLIC_URL');
    if (is_string($configured) && $configured !== '') {
        $configured = rtrim($configured, '/');
        if (
            filter_var($configured, FILTER_VALIDATE_URL) !== false
            && preg_match('/[\r\n]/', $configured) !== 1
        ) {
            return $configured;
        }
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!is_string($host) || preg_match('/[\r\n\/]/', $host) === 1) {
        $host = 'localhost';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || getenv('OPNG_FORCE_HTTPS') === '1';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/auth/request_reset.php';
    $script = is_string($script) ? $script : '/auth/request_reset.php';
    $basePath = rtrim(dirname(dirname($script)), '/');

    return ($https ? 'https' : 'http') . '://' . $host . $basePath;
}

/**
 * Build the absolute reset link for one clear token.
 */
function password_reset_link(string $token): string
{
    return password_reset_public_base_url()
        . '/auth/reset_password.php?token=' . rawurlencode($token);
}
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

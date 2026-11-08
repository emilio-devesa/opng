<?php

declare(strict_types=1);

// includes/session.php
// Session start with a hardened configuration:
//   - httponly: the cookie is not readable from JavaScript (mitigates session theft via XSS)
//   - samesite=Lax: reduces cross-site use (Lax instead of Strict so that
//     navigation from external links keeps working in a public pastebin)
//   - secure: enabled automatically when the request arrives over HTTPS
//   - use_strict_mode: PHP rejects session IDs it did not generate (anti-fixation)
//   - idle and absolute expiry
//
// Usage: call session_start_secured() instead of session_start().

const OPNG_SESSION_IDLE_SECONDS = 1800;
const OPNG_SESSION_ABSOLUTE_SECONDS = 43200;

/**
 * Configure PHP's session handler and start the session.
 *
 * The idle/absolute timestamps live in the session data, so they are checked
 * after session_start(). Expired sessions keep the CSRF token but lose every
 * authenticated identity key.
 */
function session_start_secured(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return; // already started
    }

    // Trust the web server's HTTPS state only. Forwarded headers are client-
    // controlled unless a separately configured trusted proxy validates them.
    // OPNG_FORCE_HTTPS=1 marks the cookie Secure for TLS-terminating proxies
    // that do not expose HTTPS to PHP; it does not redirect requests.
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || getenv('OPNG_FORCE_HTTPS') === '1';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    // 48 hex-equivalent characters with the largest entropy alphabet. This
    // raises the guessing cost without changing the session API (SEC-13).
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');

    // `__Host-` requires Secure, Path=/ and no Domain, so it is only valid on
    // HTTPS. Plain-HTTP development keeps the unprefixed name. PHP's session
    // API has no Priority attribute; set `Priority=High` at the TLS proxy.
    session_name($is_https ? '__Host-opng_session' : 'opng_session');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    $now = time();
    $idle_limit = session_limit_from_env('OPNG_SESSION_IDLE_SECONDS', OPNG_SESSION_IDLE_SECONDS);
    $absolute_limit = session_limit_from_env('OPNG_SESSION_ABSOLUTE_SECONDS', OPNG_SESSION_ABSOLUTE_SECONDS);

    if (!isset($_SESSION['opng_created_at'])) {
        $_SESSION['opng_created_at'] = $now;
    }

    $created_at = (int) $_SESSION['opng_created_at'];
    $last_activity = (int) ($_SESSION['opng_last_activity'] ?? $now);

    if ($now - $last_activity > $idle_limit || $now - $created_at > $absolute_limit) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['opng_created_at'] = $now;
    }

    $_SESSION['opng_last_activity'] = $now;
}

function session_limit_from_env(string $name, int $default): int
{
    $raw = getenv($name);
    if ($raw === false || $raw === '') {
        return $default;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 60]]);

    return $value === false ? $default : $value;
}

/**
 * Drop the authenticated identity while keeping the anonymous session (and its
 * CSRF token) usable. Called on timeout, version mismatch, or account removal.
 */
function session_forget_account(): void
{
    unset(
        $_SESSION['user_id'],
        $_SESSION['username'],
        $_SESSION['role'],
        $_SESSION['session_version'],
        $_SESSION['opng_authenticated_at']
    );

    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
}

/**
 * Validate that the authenticated account still exists and that its
 * `session_version` matches the session. Password changes, password resets and
 * role changes increment the stored version, invalidating every other session
 * belonging to that account (SEC-02/ROB-04).
 *
 * Called from db_connect() so every database-backed request is covered.
 */
function session_validate_account(mysqli $db): void
{
    $user_id = $_SESSION['user_id'] ?? null;
    if (!is_int($user_id) && !(is_string($user_id) && ctype_digit($user_id))) {
        return;
    }

    $user_id = (int) $user_id;
    if ($user_id < 1) {
        session_forget_account();
        return;
    }

    $stmt = $db->prepare('SELECT role, session_version FROM users WHERE id = ?');
    $stmt->bind_param('i', $user_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Session account lookup failed.');
    }

    $result = $stmt->get_result();
    $account = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    $session_version = $_SESSION['session_version'] ?? null;
    $version_is_valid = is_int($session_version)
        || (is_string($session_version) && ctype_digit($session_version));
    $stored_version = is_array($account) ? ($account['session_version'] ?? null) : null;
    $stored_is_valid = is_int($stored_version)
        || (is_string($stored_version) && ctype_digit($stored_version));

    if (
        !is_array($account)
        || !is_string($account['role'] ?? null)
        || !$version_is_valid
        || !$stored_is_valid
        || (int) $stored_version !== (int) $session_version
    ) {
        session_forget_account();
        return;
    }

    $role = $account['role'];
    if ($role !== 'admin' && $role !== 'user') {
        session_forget_account();
        return;
    }

    $_SESSION['role'] = $role;
}

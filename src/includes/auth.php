<?php

declare(strict_types=1);

// includes/auth.php
// Shared login/session transitions, including the MFA challenge (10.2).

require_once __DIR__ . '/csrf.php';

const OPNG_MFA_PENDING_TTL = 300;

/**
 * Establish the authenticated identity after every factor has passed.
 *
 * @param array{id: int|string, username: string, role: string, session_version?: int|string} $user
 */
function auth_login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['session_version'] = (int) ($user['session_version'] ?? 0);
    $_SESSION['opng_authenticated_at'] = time();
    csrf_rotate();
    unset($_SESSION['mfa_pending'], $_SESSION['redirect_after_login']);
}

/**
 * Park a password-verified login until the second factor or enrollment is done.
 *
 * @param array{id: int|string, username: string, role: string} $user
 */
function auth_begin_mfa(array $user, bool $enrollRequired, string $redirectUrl): void
{
    session_regenerate_id(true);
    $_SESSION['mfa_pending'] = [
        'user_id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'role' => (string) $user['role'],
        'enroll_required' => $enrollRequired,
        'redirect' => $redirectUrl,
        'expires' => time() + OPNG_MFA_PENDING_TTL,
    ];
    csrf_rotate();
}

/**
 * @return array{user_id: int, username: string, role: string, enroll_required: bool, redirect: string, expires: int}|null
 */
function auth_pending_mfa(): ?array
{
    $pending = $_SESSION['mfa_pending'] ?? null;
    if (!is_array($pending)) {
        return null;
    }
    $expires = $pending['expires'] ?? null;
    $userId = $pending['user_id'] ?? null;
    if (!is_int($expires) || $expires < time() || !is_int($userId) || $userId < 1) {
        unset($_SESSION['mfa_pending']);
        return null;
    }

    return [
        'user_id' => $userId,
        'username' => is_string($pending['username'] ?? null) ? $pending['username'] : '',
        'role' => is_string($pending['role'] ?? null) ? $pending['role'] : 'user',
        'enroll_required' => ($pending['enroll_required'] ?? false) === true,
        'redirect' => is_string($pending['redirect'] ?? null) ? $pending['redirect'] : '../index.php',
        'expires' => $expires,
    ];
}

function auth_clear_pending_mfa(): void
{
    unset($_SESSION['mfa_pending']);
}

/**
 * Load the pending account and complete the login.
 *
 * @return bool true when the session is now authenticated
 */
function auth_complete_pending_mfa(mysqli $db): bool
{
    $pending = auth_pending_mfa();
    if ($pending === null) {
        return false;
    }

    $stmt = $db->prepare('SELECT id, username, role, session_version FROM users WHERE id = ?');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $pending['user_id']);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();
    if (!is_array($user)) {
        auth_clear_pending_mfa();
        return false;
    }

    auth_login_user($user);
    $_SESSION['redirect_after_login'] = $pending['redirect'];

    return true;
}

/**
 * Safe post-login destination (internal relative path only).
 */
function auth_safe_redirect(?string $redirect): string
{
    if (!is_string($redirect) || $redirect === '') {
        return '../index.php';
    }
    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $redirect) === 1) {
        return '../index.php';
    }

    return $redirect;
}

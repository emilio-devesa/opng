<?php

declare(strict_types=1);

// includes/reauth.php
// Server-side confirmation for destructive administrative actions (SEC-07).
//
// CSRF tokens and JavaScript confirmations prove that a request came from the
// application, not that the account owner approved this specific action. A
// compromised admin session (or an automated POST with a valid CSRF token)
// must still present the current password before deleting data or downloading
// a backup. Failures are counted in the rate-limit table so a stolen session
// cannot be used to brute-force the password.

require_once(__DIR__ . '/ratelimit.php');
require_once(__DIR__ . '/mfa.php');

const OPNG_REAUTH_LIMIT = 5;
const OPNG_REAUTH_WINDOW = 300;

/**
 * Outcome of a password confirmation.
 *
 * Unavailable is kept separate from InvalidPassword so callers can show a
 * service error (503) instead of telling the operator the password was wrong
 * when the rate-limit or users table is unreachable (ROB-22).
 */
enum ReauthResult
{
    case Allowed;
    case InvalidPassword;
    case InvalidMfa;
    case RateLimited;
    case Unavailable;
}

/**
 * Error message key for a failed confirmation.
 */
function reauth_error_key(ReauthResult $result): string
{
    return match ($result) {
        ReauthResult::Unavailable => 'error_service_unavailable',
        ReauthResult::InvalidMfa => 'error_mfa_code',
        default => 'error_reauth',
    };
}

/**
 * Verify the current account password and, when enrolled, a second factor.
 *
 * Records only failed attempts; a success clears the account's reauth bucket.
 * Infrastructure failures fail closed and return Unavailable.
 */
function reauthenticate_user(
    mysqli $db,
    int $user_id,
    ?string $password,
    string $action = 'admin_reauth',
    ?string $mfa_code = null
): ReauthResult {
    if ($password === null || $password === '') {
        return ReauthResult::InvalidPassword;
    }

    $key = 'user:' . $user_id;
    $status = rate_limit_status($db, $action, $key, OPNG_REAUTH_LIMIT, OPNG_REAUTH_WINDOW);
    if ($status === RateLimitResult::Unavailable) {
        error_log('OPNG reauth.php: the rate-limit store is unavailable');
        return ReauthResult::Unavailable;
    }
    if ($status === RateLimitResult::Limited) {
        return ReauthResult::RateLimited;
    }

    $stmt = null;
    try {
        $stmt = $db->prepare('SELECT password_hash, mfa_enabled FROM users WHERE id = ?');
        if (!$stmt) {
            error_log('OPNG reauth.php: ' . $db->error);
            return ReauthResult::Unavailable;
        }
        $stmt->bind_param('i', $user_id);
        if (!$stmt->execute()) {
            error_log('OPNG reauth.php: ' . $stmt->error);
            return ReauthResult::Unavailable;
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $stmt->close();
        $stmt = null;

        $hash = is_array($row) && is_string($row['password_hash'] ?? null) ? $row['password_hash'] : null;
        if ($hash === null || !password_verify($password, $hash)) {
            rate_limit_hit($db, $action, $key, OPNG_REAUTH_LIMIT, OPNG_REAUTH_WINDOW);
            return ReauthResult::InvalidPassword;
        }

        // Enrolled administrators must also present a TOTP or recovery code.
        if ((int) ($row['mfa_enabled'] ?? 0) === 1) {
            if ($mfa_code === null || trim($mfa_code) === '') {
                return ReauthResult::InvalidMfa;
            }
            if (!mfa_verify_user_code($db, $user_id, $mfa_code)) {
                rate_limit_hit($db, $action, $key, OPNG_REAUTH_LIMIT, OPNG_REAUTH_WINDOW);
                return ReauthResult::InvalidMfa;
            }
        }

        rate_limit_clear($db, $action, $key, OPNG_REAUTH_WINDOW);
        return ReauthResult::Allowed;
    } catch (mysqli_sql_exception $exception) {
        error_log('OPNG reauth.php: ' . $exception->getMessage());
        return ReauthResult::Unavailable;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

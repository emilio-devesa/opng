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

const OPNG_REAUTH_LIMIT = 5;
const OPNG_REAUTH_WINDOW = 300;

/**
 * Verify the current account password for a destructive action.
 *
 * Records only failed attempts; a success clears the account's reauth bucket.
 */
function reauthenticate_user(mysqli $db, int $user_id, ?string $password, string $action = 'admin_reauth'): bool
{
    if ($password === null || $password === '') {
        return false;
    }

    $key = 'user:' . $user_id;
    $status = rate_limit_status($db, $action, $key, OPNG_REAUTH_LIMIT, OPNG_REAUTH_WINDOW);
    if ($status !== RateLimitResult::Allowed) {
        return false;
    }

    $stmt = null;
    try {
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        if (!$stmt) {
            error_log('OPNG reauth.php: ' . $db->error);
            return false;
        }
        $stmt->bind_param('i', $user_id);
        if (!$stmt->execute()) {
            error_log('OPNG reauth.php: ' . $stmt->error);
            return false;
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
            return false;
        }

        rate_limit_clear($db, $action, $key, OPNG_REAUTH_WINDOW);
        return true;
    } catch (mysqli_sql_exception $exception) {
        error_log('OPNG reauth.php: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

<?php

require_once(__DIR__ . '/session.php');

/**
 * Refresh the authenticated account's role from the database.
 *
 * The lookup is read-only: authorization reads never need `FOR UPDATE`, and
 * the session version check in session_validate_account() invalidates the
 * identity when the role changes elsewhere.
 */
function refresh_account_role(mysqli $db): ?string
{
    if (!isset($_SESSION['user_id'])) {
        unset($_SESSION['role']);
        return null;
    }

    session_validate_account($db);

    $role = $_SESSION['role'] ?? null;
    if (!is_string($role) || ($role !== 'admin' && $role !== 'user')) {
        return null;
    }

    return $role;
}

<?php

declare(strict_types=1);

require_once(__DIR__ . '/session.php');

/**
 * Return the authenticated account's role from the session.
 *
 * db_connect() has already validated the session against users.session_version,
 * so no extra query (and no locking read) is needed here. Public pages that use
 * this helper therefore do not hit `users` just to read the role.
 */
function refresh_account_role(mysqli $db): ?string
{
    if (!isset($_SESSION['user_id'])) {
        unset($_SESSION['role']);
        return null;
    }

    $role = $_SESSION['role'] ?? null;
    if (!is_string($role) || ($role !== 'admin' && $role !== 'user')) {
        return null;
    }

    return $role;
}

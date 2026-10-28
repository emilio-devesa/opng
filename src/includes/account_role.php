<?php

/**
 * Refresh the authenticated account's role from the database.
 */
function refresh_account_role(mysqli $db): ?string
{
    $user_id = http_positive_integer($_SESSION['user_id'] ?? null);
    if ($user_id === null) {
        unset($_SESSION['role']);
        return null;
    }

    $stmt = $db->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $user_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Account role lookup failed.');
    }

    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        throw new RuntimeException('Account role result is unavailable.');
    }
    $account = $result->fetch_assoc();
    $result->free();
    $stmt->close();

    $role = is_array($account) && is_string($account['role'] ?? null)
        ? $account['role']
        : null;
    if ($role !== 'admin' && $role !== 'user') {
        unset($_SESSION['role']);
        return null;
    }

    $_SESSION['role'] = $role;
    return $role;
}

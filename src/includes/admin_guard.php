<?php
declare(strict_types=1);

require_once(__DIR__ . '/http_input.php');
require_once(__DIR__ . '/account_role.php');
require_once(__DIR__ . '/error_messages.php');

/**
 * Require a valid authenticated account and return its numeric ID.
 *
 * Scripts that also allow owners (for example entry deletion) use this guard;
 * administrator-only scripts use require_admin() below.
 */
function require_authenticated_user(): int
{
    $user_id = http_positive_integer($_SESSION['user_id'] ?? null);
    if ($user_id === null) {
        opng_error_redirect('error_permission', '../error.php');
    }

    return $user_id;
}

/**
 * Require an administrator. The database connection is closed before the
 * redirect when the account is not authorized.
 */
function require_admin(mysqli $db, string $context): int
{
    $user_id = require_authenticated_user();

    try {
        $role = refresh_account_role($db);
    } catch (mysqli_sql_exception | RuntimeException $exception) {
        error_log("OPNG {$context}: " . $exception->getMessage());
        $db->close();
        opng_error_redirect('error_database_operation', '../error.php');
    }

    if ($role !== 'admin') {
        $db->close();
        opng_error_redirect('error_permission', '../error.php');
    }

    return $user_id;
}

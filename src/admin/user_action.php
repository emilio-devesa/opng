<?php
require_once('../includes/session.php');
session_start_secured();
require_once('../includes/csrf.php');
require_once('../includes/http_input.php');
require_once('../includes/account_role.php');
require_once('../database.php');

function redirect_admin(string $message): never
{
    header('Location: admin.php?msg=' . rawurlencode($message));
    exit;
}

$current_user_id = http_positive_integer($_SESSION['user_id'] ?? null);
if ($current_user_id === null) {
    header("Location: ../error.php?msg=" . urlencode("Access denied. You don't have permission to perform this action."));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    header("Location: ../error.php?msg=" . urlencode("Error: invalid CSRF token."));
    exit;
}

$target_user_id = http_positive_integer($_POST['admin_record_number'] ?? null);
$action = http_string_parameter($_POST, 'action', 16, false);
$delete_mode = http_string_parameter($_POST, 'delete_mode', 16, false);
$new_role = http_string_parameter($_POST, 'role', 16, false);
if (
    $target_user_id === null
    || !in_array($action, ['delete', 'role'], true)
    || ($action === 'delete' && !in_array($delete_mode, ['preserve', 'delete_entries'], true))
    || ($action === 'role' && !in_array($new_role, ['admin', 'user'], true))
) {
    redirect_admin('user_operation_failed');
}
if ($target_user_id === $current_user_id) {
    redirect_admin('self_change_denied');
}

$db = database_connect();
if (!$db) {
    redirect_admin('user_operation_failed');
}

$transaction_started = false;
try {
    $db->begin_transaction();
    $transaction_started = true;
    if (refresh_account_role($db) !== 'admin') {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        header("Location: ../error.php?msg=" . urlencode("Access denied. You don't have permission to perform this action."));
        exit;
    }

    $stmt = $db->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $target_user_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('User role lookup failed.');
    }
    $result = $stmt->get_result();
    if (!$result) {
        throw new RuntimeException('User role result is unavailable.');
    }
    $target = $result->fetch_assoc();
    $result->free();
    $stmt->close();
    $stmt = null;
    if (!is_array($target) || !is_string($target['role'] ?? null)) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        redirect_admin('user_not_found');
    }

    $removes_admin = $target['role'] === 'admin'
        && ($action === 'delete' || $new_role === 'user');
    if ($removes_admin) {
        $administrators = $db->query("SELECT id FROM users WHERE role = 'admin' FOR UPDATE");
        if (!$administrators) {
            throw new RuntimeException('Administrator list is unavailable.');
        }
        $administrator_count = $administrators->num_rows;
        $administrators->free();
        if ($administrator_count <= 1) {
            $db->rollback();
            $transaction_started = false;
            $db->close();
            redirect_admin('last_admin_protected');
        }
    }

    if ($action === 'role') {
        $stmt = $db->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->bind_param('si', $new_role, $target_user_id);
        if (!$stmt->execute()) {
            throw new RuntimeException('User role update failed.');
        }
        $stmt->close();
        $stmt = null;
        $message = 'user_role_changed';
    } else {
        if ($delete_mode === 'preserve') {
            $stmt = $db->prepare('UPDATE entries SET user_id = NULL WHERE user_id = ?');
            $stmt->bind_param('i', $target_user_id);
            if (!$stmt->execute()) {
                throw new RuntimeException('User entries could not be preserved anonymously.');
            }
            $stmt->close();
            $stmt = null;
        } else {
            $stmt = $db->prepare('DELETE FROM entries WHERE user_id = ?');
            $stmt->bind_param('i', $target_user_id);
            if (!$stmt->execute()) {
                throw new RuntimeException('User entries could not be deleted.');
            }
            $stmt->close();
            $stmt = null;
        }

        $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
        $stmt->bind_param('i', $target_user_id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            throw new RuntimeException('User account deletion failed.');
        }
        $stmt->close();
        $stmt = null;
        $message = $delete_mode === 'preserve'
            ? 'user_deleted_preserved'
            : 'user_deleted_entries';
    }

    $db->commit();
    $transaction_started = false;
    $db->close();
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log('OPNG user_action.php: ' . $exception->getMessage());
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if ($transaction_started) {
        $db->rollback();
    }
    $db->close();
    redirect_admin('user_operation_failed');
}

redirect_admin($message);

<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// auth/trash_action.php
// Restore one of the current account's trashed threads (10.3).
session_start_secured();
require_once('../includes/admin_guard.php');
require_once('../includes/trash.php');
require_once('../includes/audit_log.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    opng_error_redirect('error_csrf', '../error.php');
}

$action = http_string_parameter($_POST, 'action', 16, false);
$input_ID = http_string_parameter($_POST, 'input_ID', 255, false);
if ($action !== 'restore' || $input_ID === null || !http_valid_entry_id($input_ID)) {
    opng_error_redirect('error_invalid_id', '../error.php');
}

$db = database_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable', '../error.php');
}
$user_id = require_authenticated_user();
$role = refresh_account_role($db) ?? 'guest';

$thread = trash_thread_for_restore($db, $input_ID);
if (
    $thread === null
    || ($role !== 'admin' && (int) ($thread['user_id'] ?? 0) !== $user_id)
) {
    $db->close();
    opng_error_redirect('error_permission', '../error.php');
}

$restored = trash_restore_thread($db, $input_ID) > 0;
if ($restored) {
    audit_log_record($db, $user_id, 'entry.restore', $input_ID);
}
$db->close();

header('Location: trash.php?msg=' . ($restored ? 'restored' : 'restore_failed'));
exit;

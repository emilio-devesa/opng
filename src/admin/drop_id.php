<?php
// Paste deletion (soft delete). The whole thread moves to the trash (10.3).
require_once('../includes/session.php');
session_start_secured();
require_once('../includes/csrf.php');
require_once('../database.php');
require_once('../includes/http_input.php');
require_once('../includes/admin_guard.php');
require_once('../includes/reauth.php');
require_once("../includes/error_messages.php");
require_once('../includes/admin_metrics.php');
require_once('../includes/audit_log.php');
require_once('../includes/user_quota.php');
require_once('../includes/trash.php');

// Verify that the request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// Verify the CSRF token
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    opng_error_redirect('error_csrf', '../error.php');
}

// Deletion must be explicitly confirmed even when JavaScript is unavailable
// (UX-06). The visible checkbox is a server-side requirement; client-side
// `required` alone would not protect requests made without JavaScript.
if (($_POST['confirm_delete'] ?? null) !== 'yes') {
    opng_error_redirect('error_confirmation_required', '../error.php');
}

// Validate the supplied ID
$input_ID = http_string_parameter($_POST, 'input_ID', 255, false);
if ($input_ID === null || !http_valid_entry_id($input_ID)) {
    opng_error_redirect('error_invalid_id', '../error.php');
}

$user_id = require_authenticated_user();
// Connect to the database
$db = database_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable', '../error.php');
}

// Moving data to the trash requires the current password (and the second
// factor when enrolled) in addition to CSRF and the explicit confirmation
// (SEC-07/10.2).
$current_password = http_string_parameter($_POST, 'current_password', 72, false);
$mfa_code = http_string_parameter($_POST, 'mfa_code', 32, false);
$reauth = reauthenticate_user($db, $user_id, $current_password, 'entry_delete', $mfa_code);
if ($reauth !== ReauthResult::Allowed) {
    $db->close();
    opng_error_redirect(reauth_error_key($reauth), '../error.php');
}

$transaction_started = false;
$trashed = false;
$trashed_rows = 0;
try {
    $db->begin_transaction();
    $transaction_started = true;

    // Global lock order (ROB-15): storage_counters -> users -> entries.
    $global_used = global_quota_lock($db);
    if ($global_used === null) {
        throw new RuntimeException('Global storage counter is unavailable.');
    }

    $user_role = refresh_account_role($db);
    if ($user_role === null) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_permission', '../error.php');
    }

    // Discover the owner first, then lock the account before the entry. The
    // row is re-read with FOR UPDATE below, so the ownership check always
    // comes from the locked row.
    $stmt = $db->prepare('SELECT user_id FROM entries WHERE id = ?');
    $stmt->bind_param('s', $input_ID);
    if (!$stmt->execute()) {
        throw new RuntimeException('Entry owner lookup failed.');
    }
    $owner_result = $stmt->get_result();
    $owner_row = $owner_result ? $owner_result->fetch_assoc() : null;
    if ($owner_result instanceof mysqli_result) {
        $owner_result->free();
    }
    $stmt->close();
    $stmt = null;
    $entry_owner_id = is_array($owner_row) && $owner_row['user_id'] !== null
        ? (int) $owner_row['user_id']
        : null;
    if ($entry_owner_id !== null && lock_user_quota($db, $entry_owner_id) === null) {
        throw new RuntimeException('Entry owner was not found while checking storage quota.');
    }

    // Lock the target entry before touching the thread.
    $stmt = $db->prepare(
        'SELECT user_id, deleted_at FROM entries WHERE id = ? FOR UPDATE'
    );
    $stmt->bind_param('s', $input_ID);
    if (!$stmt->execute()) {
        throw new RuntimeException('Entry lock failed.');
    }
    $size_result = $stmt->get_result();
    $size_row = $size_result ? $size_result->fetch_assoc() : null;
    if ($size_result instanceof mysqli_result) {
        $size_result->free();
    }
    $stmt->close();
    $stmt = null;

    if (!is_array($size_row)) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_entry_not_found', '../error.php');
    }

    $locked_owner_id = $size_row['user_id'] !== null ? (int) $size_row['user_id'] : null;
    if ($locked_owner_id !== $entry_owner_id) {
        throw new RuntimeException('Entry ownership changed while the deletion locked it.');
    }
    if ($user_role !== 'admin' && $locked_owner_id !== $user_id) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_permission', '../error.php');
    }

    // Storage and quota counters stay charged until the scheduled purge
    // physically removes the rows, so a restore never re-checks quotas.
    $trashed_rows = trash_soft_delete_thread($db, $input_ID);
    $trashed = $trashed_rows > 0;

    if (!$db->commit()) {
        throw new RuntimeException('Trash transaction could not be committed.');
    }
    $transaction_started = false;

    if ($trashed) {
        // Audit after commit: the audit log is best-effort, and this keeps the
        // opportunistic retention DELETE outside the business transaction
        // (ROB-20).
        audit_log_record($db, $user_id, 'entry.trash', $input_ID);
        admin_metrics_invalidate($db);
    }
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log("OPNG drop_id.php: " . $exception->getMessage());
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if ($transaction_started) {
        $db->rollback();
    }
    $db->close();
    opng_error_redirect('error_database_operation', '../error.php');
}

$db->close();
if ($trashed) {
    header("Location: ../index.php?msg=" . urlencode("Paste moved to trash"));
    exit;
}

opng_error_redirect('error_permission', '../error.php');

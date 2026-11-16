<?php

declare(strict_types=1);

// admin/trash_action.php
// Restore or permanently purge trashed threads (10.3).

require_once('../includes/session.php');
session_start_secured();
require_once('../includes/csrf.php');
require_once('../includes/http_input.php');
require_once('../includes/error_messages.php');
require_once('../includes/admin_guard.php');
require_once('../includes/reauth.php');
require_once('../includes/trash.php');
require_once('../includes/audit_log.php');
require_once('../includes/admin_metrics.php');
require_once('../database.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    opng_error_redirect('error_csrf', '../error.php');
}

$db = database_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable', '../error.php');
}
$session_user_id = require_admin($db, 'trash_action.php');

$action = http_string_parameter($_POST, 'action', 24, false);
$input_ID = http_string_parameter($_POST, 'input_ID', 255, false);
if (!in_array($action, ['restore', 'purge', 'purge_expired'], true)) {
    $db->close();
    opng_error_redirect('error_invalid_id', '../error.php');
}
if ($action !== 'purge_expired' && ($input_ID === null || !http_valid_entry_id($input_ID))) {
    $db->close();
    opng_error_redirect('error_invalid_id', '../error.php');
}

if ($action === 'restore') {
    $thread = trash_thread_for_restore($db, (string) $input_ID);
    if ($thread === null) {
        $db->close();
        opng_error_redirect('error_entry_not_found', '../error.php');
    }
    $restored = trash_restore_thread($db, (string) $input_ID) > 0;
    if ($restored) {
        audit_log_record($db, $session_user_id, 'entry.restore', (string) $input_ID);
        admin_metrics_invalidate($db);
    }
    $db->close();
    header('Location: trash.php?msg=' . ($restored ? 'restored' : 'restore_failed'));
    exit;
}

// Permanent deletion is irreversible: require the explicit checkbox and the
// current password (plus MFA when enrolled) (SEC-07/10.2).
if (($_POST['confirm_purge'] ?? null) !== 'yes') {
    $db->close();
    opng_error_redirect('error_confirmation_required', '../error.php');
}
$current_password = http_string_parameter($_POST, 'current_password', 72, false);
$mfa_code = http_string_parameter($_POST, 'mfa_code', 32, false);
$reauth = reauthenticate_user($db, $session_user_id, $current_password, 'trash_purge', $mfa_code);
if ($reauth !== ReauthResult::Allowed) {
    $db->close();
    opng_error_redirect(reauth_error_key($reauth), '../error.php');
}

try {
    if ($action === 'purge') {
        $purged = trash_purge_thread($db, (string) $input_ID);
        if ($purged) {
            audit_log_record($db, $session_user_id, 'entry.purge', (string) $input_ID);
            admin_metrics_invalidate($db);
        }
        $db->close();
        header('Location: trash.php?msg=' . ($purged ? 'purged' : 'restore_failed'));
        exit;
    }

    $result = trash_purge_expired($db);
    audit_log_record(
        $db,
        $session_user_id,
        'trash.purge_expired',
        'entries:' . (int) $result['purged']
    );
    admin_metrics_invalidate($db);
    $db->close();
    header('Location: trash.php?msg=purged_expired');
    exit;
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log('OPNG trash_action.php: ' . $exception->getMessage());
    $db->close();
    opng_error_redirect('error_database_operation', '../error.php');
}

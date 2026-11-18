<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// admin/external_backup_action.php
// Run or verify an external backup (10.7).
session_start_secured();
require_once('../includes/admin_guard.php');
require_once('../includes/reauth.php');
require_once('../includes/external_backup.php');
require_once('../includes/audit_log.php');
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
$session_user_id = require_admin($db, 'external_backup_action.php');

$action = http_string_parameter($_POST, 'action', 16, false);
if (!in_array($action, ['run', 'verify'], true)) {
    $db->close();
    opng_error_redirect('error_invalid_id', '../error.php');
}

$current_password = http_string_parameter($_POST, 'current_password', 72, false);
$mfa_code = http_string_parameter($_POST, 'mfa_code', 32, false);
$reauth = reauthenticate_user($db, $session_user_id, $current_password, 'external_backup', $mfa_code);
if ($reauth !== ReauthResult::Allowed) {
    $db->close();
    opng_error_redirect(reauth_error_key($reauth), '../error.php');
}

$config = external_backup_config();
if ($config['target'] === 'none') {
    $db->close();
    header('Location: external_backup.php?msg=disabled');
    exit;
}
$config_errors = external_backup_validate_config($config);
if ($config_errors !== []) {
    $db->close();
    opng_error_redirect('error_external_backup_config', '../error.php');
}

try {
    if ($action === 'run') {
        $result = external_backup_run($db);
        audit_log_record($db, $session_user_id, 'backup.external', $result['name']);
        $db->close();
        header('Location: external_backup.php?msg=created');
        exit;
    }

    $file = http_string_parameter($_POST, 'file', 255, false);
    if (
        $file === null
        || preg_match('/\Aopng-[A-Za-z0-9._-]+\.(?:sql|enc)\z/', $file) !== 1
        || $config['target'] !== 'local'
    ) {
        $db->close();
        opng_error_redirect('error_invalid_id', '../error.php');
    }
    $path = rtrim($config['directory'], '/') . '/' . $file;
    $result = external_backup_verify_file($db, $path);
    audit_log_record($db, $session_user_id, 'backup.verify', $file);
    $db->close();
    header('Location: external_backup.php?msg=verified');
    exit;
} catch (mysqli_sql_exception | RuntimeException $exception) {
    opng_log_exception('external_backup_failed', $exception, [
        'action' => $action,
        'actor_id' => $session_user_id,
    ]);
    $db->close();
    header('Location: external_backup.php?msg=failed');
    exit;
}

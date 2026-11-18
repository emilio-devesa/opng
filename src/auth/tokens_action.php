<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// auth/tokens_action.php
// Create or revoke one of the current account's API tokens (10.4).
session_start_secured();
require_once('../includes/admin_guard.php');
require_once('../includes/reauth.php');
require_once('../includes/api_tokens.php');
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
if (!in_array($action, ['create', 'revoke'], true)) {
    opng_error_redirect('error_invalid_id', '../error.php');
}

$db = database_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable', '../error.php');
}
$user_id = require_authenticated_user();

if ($action === 'create') {
    $name = http_string_parameter($_POST, 'name', 80) ?? '';
    $raw_expiry = $_POST['expires_days'] ?? '';
    $expires_days = null;
    if (is_string($raw_expiry) && $raw_expiry !== '') {
        $expires_days = filter_var(
            $raw_expiry,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => OPNG_API_TOKEN_MAX_DAYS]]
        );
        if ($expires_days === false) {
            $db->close();
            header('Location: tokens.php?msg=failed');
            exit;
        }
    }
    $scopes = $_POST['scopes'] ?? [];
    $created = api_token_create($db, $user_id, $name, $scopes, $expires_days);
    if ($created === null) {
        $db->close();
        header('Location: tokens.php?msg=failed');
        exit;
    }
    audit_log_record($db, $user_id, 'api_token.create', 'token:' . $created['id']);
    $_SESSION['api_token_created'] = ['id' => $created['id'], 'clear' => $created['clear']];
    $db->close();
    header('Location: tokens.php?msg=created');
    exit;
}

$token_id = http_positive_integer($_POST['token_id'] ?? null);
if ($token_id === null) {
    $db->close();
    opng_error_redirect('error_invalid_id', '../error.php');
}
$current_password = http_string_parameter($_POST, 'current_password', 72, false);
$mfa_code = http_string_parameter($_POST, 'mfa_code', 32, false);
$reauth = reauthenticate_user($db, $user_id, $current_password, 'api_token_revoke', $mfa_code);
if ($reauth !== ReauthResult::Allowed) {
    $db->close();
    opng_error_redirect(reauth_error_key($reauth), '../error.php');
}

$revoked = api_token_revoke($db, $token_id, $user_id);
if ($revoked) {
    audit_log_record($db, $user_id, 'api_token.revoke', 'token:' . $token_id);
}
$db->close();
header('Location: tokens.php?msg=' . ($revoked ? 'revoked' : 'failed'));
exit;

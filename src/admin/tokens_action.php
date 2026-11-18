<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// admin/tokens_action.php
// Revoke any API token (10.4).
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

$db = database_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable', '../error.php');
}
$session_user_id = require_admin($db, 'tokens_action.php');

$token_id = http_positive_integer($_POST['token_id'] ?? null);
if ($token_id === null) {
    $db->close();
    opng_error_redirect('error_invalid_id', '../error.php');
}
$current_password = http_string_parameter($_POST, 'current_password', 72, false);
$mfa_code = http_string_parameter($_POST, 'mfa_code', 32, false);
$reauth = reauthenticate_user($db, $session_user_id, $current_password, 'api_token_admin_revoke', $mfa_code);
if ($reauth !== ReauthResult::Allowed) {
    $db->close();
    opng_error_redirect(reauth_error_key($reauth), '../error.php');
}

$revoked = api_token_revoke($db, $token_id, null);
if ($revoked) {
    audit_log_record($db, $session_user_id, 'api_token.admin_revoke', 'token:' . $token_id);
}
$db->close();
header('Location: tokens.php?msg=' . ($revoked ? 'revoked' : 'failed'));
exit;

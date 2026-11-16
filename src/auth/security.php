<?php

declare(strict_types=1);

// auth/security.php
// Account security page: MFA status, enrollment, recovery codes and links to
// personal API tokens and the trash (10.2/10.3/10.4).

require_once('../includes/session.php');
session_start_secured();
require_once('../includes/csrf.php');
require_once('../includes/http_input.php');
require_once('../includes/error_messages.php');
require_once('../includes/assets.php');
require_once('../includes/admin_guard.php');
require_once('../includes/reauth.php');
require_once('../includes/mfa.php');
require_once('../includes/audit_log.php');
require_once('../database.php');

$asset_version = opng_asset_version();

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$db = database_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable', '../error.php');
}

$user_id = require_authenticated_user();
$role = refresh_account_role($db) ?? 'guest';
$is_admin = $role === 'admin';
$record = mfa_user_record($db, $user_id);
if ($record === null) {
    $db->close();
    opng_error_redirect('error_auth_unavailable', '../error.php');
}
$mfa_enabled = $record['mfa_enabled'];
$is_first_admin = $is_admin && mfa_is_first_admin($db, $user_id);

$form_error_key = '';
$recovery_codes = [];
$enroll_secret = null;

// A fresh secret is generated on demand and kept in the session until the
// code is confirmed, so a reload does not silently rotate the key.
$security_enrollment = $_SESSION['mfa_security_enroll'] ?? null;
if (
    !is_array($security_enrollment)
    || !is_string($security_enrollment['secret'] ?? null)
    || !is_int($security_enrollment['expires'] ?? null)
    || $security_enrollment['expires'] < time()
) {
    unset($_SESSION['mfa_security_enroll']);
    $security_enrollment = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        opng_error_redirect('error_csrf', '../error.php');
    }
    $action = http_string_parameter($_POST, 'action', 24, false) ?? '';

    if ($action === 'confirm_enroll') {
        if (!$is_admin || $mfa_enabled) {
            $form_error_key = 'error_permission';
        } elseif ($security_enrollment === null) {
            $form_error_key = 'error_mfa_code';
        } elseif (mfa_key() === null) {
            $form_error_key = 'error_mfa_unavailable';
        } else {
            $code = http_string_parameter($_POST, 'mfa_code', 16, false) ?? '';
            if (!mfa_verify_totp($security_enrollment['secret'], $code)) {
                $form_error_key = 'error_mfa_code';
            } else {
                $recovery_codes = mfa_generate_recovery_codes();
                $enabled = mfa_enable_for_user(
                    $db,
                    $user_id,
                    $security_enrollment['secret'],
                    $recovery_codes
                );
                audit_log_record(
                    $db,
                    $user_id,
                    $enabled ? 'auth.mfa_enrolled' : 'auth.mfa_enroll_failed',
                    ''
                );
                if (!$enabled) {
                    $form_error_key = 'error_mfa_unavailable';
                } else {
                    unset($_SESSION['mfa_security_enroll']);
                    $mfa_enabled = true;
                }
            }
        }
    } elseif ($action === 'disable') {
        if ($is_first_admin) {
            $form_error_key = 'error_mfa_first_admin';
        } elseif (!$mfa_enabled) {
            $form_error_key = 'error_mfa_code';
        } else {
            $password = http_string_parameter($_POST, 'current_password', 72, false);
            $code = http_string_parameter($_POST, 'mfa_code', 32, false) ?? '';
            $reauth = reauthenticate_user($db, $user_id, $password, 'security_mfa_disable', $code);
            if ($reauth !== ReauthResult::Allowed) {
                $form_error_key = reauth_error_key($reauth);
            } elseif (mfa_disable_for_user($db, $user_id)) {
                audit_log_record($db, $user_id, 'auth.mfa_disabled', '');
                $mfa_enabled = false;
            } else {
                $form_error_key = 'error_mfa_unavailable';
            }
        }
    } elseif ($action === 'regenerate') {
        if (!$mfa_enabled) {
            $form_error_key = 'error_mfa_code';
        } else {
            $password = http_string_parameter($_POST, 'current_password', 72, false);
            $code = http_string_parameter($_POST, 'mfa_code', 32, false) ?? '';
            $reauth = reauthenticate_user($db, $user_id, $password, 'security_mfa_recovery', $code);
            if ($reauth !== ReauthResult::Allowed) {
                $form_error_key = reauth_error_key($reauth);
            } else {
                $recovery_codes = mfa_generate_recovery_codes();
                if (mfa_replace_recovery_codes($db, $user_id, $recovery_codes)) {
                    audit_log_record($db, $user_id, 'auth.mfa_recovery_regenerated', '');
                } else {
                    $recovery_codes = [];
                    $form_error_key = 'error_mfa_unavailable';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['enroll'])) {
    if ($is_admin && !$mfa_enabled) {
        if (mfa_key() === null) {
            $form_error_key = 'error_mfa_unavailable';
        } else {
            $security_enrollment = [
                'secret' => mfa_generate_secret(),
                'expires' => time() + 900,
            ];
            $_SESSION['mfa_security_enroll'] = $security_enrollment;
        }
    }
}
if ($security_enrollment !== null && !$mfa_enabled) {
    $enroll_secret = $security_enrollment['secret'];
}

$db->close();

if ($form_error_key !== '') {
    http_response_code(opng_error_status($form_error_key));
}

$lang_attr = 'en';
$page_title = 'Account security';
$page_title_i18n = 'security_title';
$base = '../';
require(__DIR__ . '/../includes/layout_header.php');
?>
    <section class="auth-page security-page">
        <h1 data-i18n="security_title">Account security</h1>

        <?php if ($form_error_key !== ''): ?>
        <p class="form-error" id="form-error" role="alert" data-i18n="<?php echo htmlspecialchars($form_error_key, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars(opng_error_text($form_error_key), ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <?php endif; ?>

        <?php if ($recovery_codes !== []): ?>
        <section class="box" aria-labelledby="recovery-title">
            <h2 id="recovery-title" data-i18n="mfa_recovery_title">Recovery codes</h2>
            <p role="status" data-i18n="mfa_recovery_notice">Store these one-time codes somewhere safe. Each code works once and they will not be shown again.</p>
            <ul class="recovery-code-list">
                <?php foreach ($recovery_codes as $recovery_code): ?>
                <li><code><?php echo htmlspecialchars($recovery_code, ENT_QUOTES, 'UTF-8'); ?></code></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <section class="box" aria-labelledby="mfa-title">
            <h2 id="mfa-title" data-i18n="mfa_section_title">Two-factor authentication</h2>
            <?php if (!$is_admin): ?>
                <p data-i18n="mfa_admin_only">Two-factor authentication is available for administrator accounts.</p>
            <?php elseif ($mfa_enabled): ?>
                <p role="status" data-i18n="mfa_status_enabled">Two-factor authentication is enabled for this account.</p>
                <?php if ($is_first_admin): ?>
                <p class="form-help" data-i18n="mfa_first_admin_locked">The first administrator must keep two-factor authentication enabled.</p>
                <?php else: ?>
                <form method="post" class="auth-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="disable">
                    <p>
                        <label for="disable_password" data-i18n="reauth_password">Current password:</label>
                        <input id="disable_password" type="password" name="current_password" maxlength="72" autocomplete="current-password" required>
                    </p>
                    <p>
                        <label for="disable_code" data-i18n="mfa_code">Authentication code:</label>
                        <input id="disable_code" type="text" name="mfa_code" maxlength="32" inputmode="numeric" autocomplete="one-time-code" required>
                    </p>
                    <button type="submit" class="danger" data-i18n="mfa_disable">Disable two-factor authentication</button>
                </form>
                <?php endif; ?>
                <form method="post" class="auth-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="regenerate">
                    <p>
                        <label for="regen_password" data-i18n="reauth_password">Current password:</label>
                        <input id="regen_password" type="password" name="current_password" maxlength="72" autocomplete="current-password" required>
                    </p>
                    <p>
                        <label for="regen_code" data-i18n="mfa_code">Authentication code:</label>
                        <input id="regen_code" type="text" name="mfa_code" maxlength="32" inputmode="numeric" autocomplete="one-time-code" required>
                    </p>
                    <button type="submit" data-i18n="mfa_regenerate">Generate new recovery codes</button>
                </form>
            <?php elseif ($enroll_secret !== null): ?>
                <p data-i18n="mfa_enroll_intro">Add this account to your authenticator app, then enter the six-digit code it shows.</p>
                <dl class="mfa-secret">
                    <div><dt data-i18n="mfa_secret_label">Secret key:</dt><dd><code><?php echo htmlspecialchars($enroll_secret, ENT_QUOTES, 'UTF-8'); ?></code></dd></div>
                    <div><dt data-i18n="mfa_uri_label">Setup URI:</dt><dd><code class="mfa-uri"><?php echo htmlspecialchars(mfa_otpauth_uri($enroll_secret, $record['username']), ENT_QUOTES, 'UTF-8'); ?></code></dd></div>
                </dl>
                <form method="post" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="confirm_enroll">
                    <p>
                        <label for="enroll_code" data-i18n="mfa_code">Authentication code:</label>
                        <input id="enroll_code" type="text" name="mfa_code" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required autofocus>
                    </p>
                    <button type="submit" data-i18n="mfa_activate">Activate</button>
                </form>
            <?php else: ?>
                <p data-i18n="mfa_status_disabled">Two-factor authentication is not enabled for this account.</p>
                <p><a class="button" href="security.php?enroll=1" data-i18n="mfa_enable">Enable two-factor authentication</a></p>
            <?php endif; ?>
        </section>

        <section class="box" aria-labelledby="account-tools-title">
            <h2 id="account-tools-title" data-i18n="account_tools">Account tools</h2>
            <ul class="account-tools-list">
                <li><a href="tokens.php" data-i18n="api_tokens_title">API tokens</a></li>
                <li><a href="trash.php" data-i18n="trash_title">Trash</a></li>
                <li><a href="change_password.php" data-i18n="change_password">Change password</a></li>
            </ul>
        </section>
    </section>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>

<?php

declare(strict_types=1);

// auth/mfa_enroll.php
// Mandatory first-administrator enrollment (10.2).

require_once('../includes/session.php');
session_start_secured();
require_once('../includes/csrf.php');
require_once('../includes/http_input.php');
require_once('../includes/error_messages.php');
require_once('../includes/assets.php');
require_once('../includes/ratelimit.php');
require_once('../includes/auth.php');
require_once('../includes/mfa.php');
require_once('../includes/audit_log.php');
require_once('../database.php');

$asset_version = opng_asset_version();

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$pending = auth_pending_mfa();
if ($pending === null) {
    header('Location: login.php');
    exit;
}
if (!$pending['enroll_required']) {
    header('Location: mfa.php');
    exit;
}

if (mfa_key() === null) {
    // Fail closed: without the encryption key no secret can be stored, and the
    // doctor reports the misconfiguration before traffic is served.
    $lang_attr = 'en';
    $page_title = 'Two-factor enrollment unavailable';
    $page_title_i18n = 'mfa_unavailable_title';
    $base = '../';
    require(__DIR__ . '/../includes/layout_header.php');
    ?>
    <section class="auth-page">
        <h1 data-i18n="mfa_unavailable_title">Two-factor enrollment unavailable</h1>
        <p role="alert" data-i18n="error_mfa_unavailable"><?php echo htmlspecialchars(opng_error_text('error_mfa_unavailable'), ENT_QUOTES, 'UTF-8'); ?></p>
        <p><a href="mfa.php?cancel=1" data-i18n="return_to_sign_in">Return to sign in</a></p>
    </section>
    <?php require(__DIR__ . '/../includes/layout_footer.php'); ?>
    <?php
    exit;
}

$enrollment = $_SESSION['mfa_enroll'] ?? null;
if (
    !is_array($enrollment)
    || !is_string($enrollment['secret'] ?? null)
    || !is_int($enrollment['expires'] ?? null)
    || $enrollment['expires'] < time()
) {
    $enrollment = [
        'secret' => mfa_generate_secret(),
        'expires' => time() + 900,
    ];
    $_SESSION['mfa_enroll'] = $enrollment;
}
$secret = $enrollment['secret'];
$otpauth = mfa_otpauth_uri($secret, $pending['username']);

$form_error_key = '';
$status = 200;
$recovery_codes = [];
$redirect = auth_safe_redirect($pending['redirect']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        opng_error_redirect('error_csrf', '../error.php');
    }

    $code = http_string_parameter($_POST, 'mfa_code', 16, false) ?? '';
    $db = database_connect();
    if (!$db) {
        opng_error_redirect('error_database_unavailable', '../error.php');
    }

    $limit = rate_limit_hit($db, 'mfa_enroll', 'user:' . $pending['user_id'], 5, 300);
    if ($limit === RateLimitResult::Unavailable) {
        $db->close();
        opng_error_redirect('error_service_unavailable', '../error.php');
    }
    if ($limit === RateLimitResult::Limited) {
        $db->close();
        opng_error_redirect('error_mfa_rate_limit', '../error.php');
    }

    if (!mfa_verify_totp($secret, $code)) {
        $db->close();
        $form_error_key = 'error_mfa_code';
        $status = opng_error_status($form_error_key);
    } else {
        $recovery_codes = mfa_generate_recovery_codes();
        $enabled = mfa_enable_for_user($db, $pending['user_id'], $secret, $recovery_codes);
        audit_log_record(
            $db,
            $pending['user_id'],
            $enabled ? 'auth.mfa_enrolled' : 'auth.mfa_enroll_failed',
            ''
        );
        if (!$enabled) {
            $db->close();
            opng_error_redirect('error_mfa_unavailable', '../error.php');
        }

        unset($_SESSION['mfa_enroll']);
        rate_limit_clear($db, 'mfa_enroll', 'user:' . $pending['user_id'], 300);
        $redirect = auth_safe_redirect($pending['redirect']);
        $completed = auth_complete_pending_mfa($db);
        $db->close();
        if (!$completed) {
            auth_clear_pending_mfa();
            header('Location: login.php');
            exit;
        }
        // The clear recovery codes are shown exactly once, on this response.
        $_SESSION['mfa_recovery_notice'] = true;
        $pending = null;
    }
}

if ($form_error_key !== '') {
    http_response_code($status);
}

$lang_attr = 'en';
$page_title = 'Set up two-factor authentication';
$page_title_i18n = 'mfa_enroll_title';
$base = '../';
require(__DIR__ . '/../includes/layout_header.php');
?>
    <section class="auth-page">
        <h1 data-i18n="mfa_enroll_title">Set up two-factor authentication</h1>
        <?php if ($recovery_codes !== []): ?>
            <p role="status" data-i18n="mfa_enrolled_notice">Two-factor authentication is active. Store these one-time recovery codes somewhere safe; they will not be shown again.</p>
            <ul class="recovery-code-list">
                <?php foreach ($recovery_codes as $recovery_code): ?>
                <li><code><?php echo htmlspecialchars($recovery_code, ENT_QUOTES, 'UTF-8'); ?></code></li>
                <?php endforeach; ?>
            </ul>
            <p><a class="button" href="<?php echo htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8'); ?>" data-i18n="mfa_continue">Continue</a></p>
        <?php else: ?>
            <p data-i18n="mfa_enroll_intro">Add this account to your authenticator app, then enter the six-digit code it shows.</p>
            <dl class="mfa-secret">
                <div><dt data-i18n="mfa_secret_label">Secret key:</dt><dd><code><?php echo htmlspecialchars($secret, ENT_QUOTES, 'UTF-8'); ?></code></dd></div>
                <div><dt data-i18n="mfa_uri_label">Setup URI:</dt><dd><code class="mfa-uri"><?php echo htmlspecialchars($otpauth, ENT_QUOTES, 'UTF-8'); ?></code></dd></div>
            </dl>
            <?php if ($form_error_key !== ''): ?>
            <p class="form-error" id="form-error" role="alert" data-i18n="<?php echo htmlspecialchars($form_error_key, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars(opng_error_text($form_error_key), ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <?php endif; ?>
            <form method="post" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <p>
                    <label for="mfa_code" data-i18n="mfa_code">Authentication code:</label>
                    <input id="mfa_code" type="text" name="mfa_code" maxlength="6" inputmode="numeric" autocomplete="one-time-code" spellcheck="false"<?php echo $form_error_key !== '' ? ' aria-invalid="true" aria-describedby="form-error" autofocus' : ' autofocus'; ?> required>
                </p>
                <button type="submit" data-i18n="mfa_activate">Activate</button>
            </form>
            <p><a href="mfa.php?cancel=1" data-i18n="mfa_cancel">Cancel and return to sign in</a></p>
        <?php endif; ?>
    </section>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>

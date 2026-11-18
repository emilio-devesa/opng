<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// auth/mfa.php
// Second-factor challenge for administrators that already enrolled (10.2).
session_start_secured();
require_once('../includes/ratelimit.php');
require_once('../includes/auth.php');
require_once('../includes/mfa.php');
require_once('../includes/audit_log.php');
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

if (isset($_GET['cancel'])) {
    auth_clear_pending_mfa();
    header('Location: login.php');
    exit;
}

$pending = auth_pending_mfa();
if ($pending === null) {
    header('Location: login.php');
    exit;
}
if ($pending['enroll_required']) {
    header('Location: mfa_enroll.php');
    exit;
}

$form_error_key = '';
$posted_code = '';
$status = 200;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        opng_error_redirect('error_csrf', '../error.php');
    }

    $posted_code = http_string_parameter($_POST, 'mfa_code', 32, false) ?? '';
    if ($posted_code === '') {
        $form_error_key = 'error_mfa_code';
    } else {
        $db = database_connect();
        if (!$db) {
            opng_error_redirect('error_database_unavailable', '../error.php');
        }

        $limit = rate_limit_hit(
            $db,
            'mfa_challenge',
            'user:' . $pending['user_id'] . '|' . client_ip(),
            5,
            300
        );
        if ($limit === RateLimitResult::Unavailable) {
            $db->close();
            opng_error_redirect('error_service_unavailable', '../error.php');
        }
        if ($limit === RateLimitResult::Limited) {
            audit_log_record($db, $pending['user_id'], 'auth.mfa_limited', '');
            $db->close();
            opng_error_redirect('error_mfa_rate_limit', '../error.php');
        }

        $verified = mfa_verify_user_code($db, $pending['user_id'], $posted_code);
        audit_log_record(
            $db,
            $pending['user_id'],
            $verified ? 'auth.mfa_success' : 'auth.mfa_failed',
            ''
        );

        if (!$verified) {
            $db->close();
            $form_error_key = 'error_mfa_code';
            $status = opng_error_status($form_error_key);
        } else {
            rate_limit_clear($db, 'mfa_challenge', 'user:' . $pending['user_id'] . '|' . client_ip(), 300);
            $redirect = auth_safe_redirect($pending['redirect']);
            $completed = auth_complete_pending_mfa($db);
            $db->close();
            if (!$completed) {
                auth_clear_pending_mfa();
                header('Location: login.php');
                exit;
            }
            header('Location: ' . $redirect);
            exit;
        }
    }
}

if ($form_error_key !== '') {
    http_response_code($status);
}

$lang_attr = 'en';
$page_title = 'Two-factor authentication';
$page_title_i18n = 'mfa_title';
$base = '../';
require(__DIR__ . '/../includes/layout_header.php');
?>
    <section class="auth-page">
        <h1 data-i18n="mfa_title">Two-factor authentication</h1>
        <p data-i18n="mfa_challenge_intro">Enter the six-digit code from your authenticator app. A recovery code also works.</p>
        <?php if ($form_error_key !== ''): ?>
        <p class="form-error" id="form-error" role="alert" data-i18n="<?php echo htmlspecialchars($form_error_key, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars(opng_error_text($form_error_key), ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <?php endif; ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <p>
                <label for="mfa_code" data-i18n="mfa_code">Authentication code:</label>
                <input id="mfa_code" type="text" name="mfa_code" maxlength="32" inputmode="numeric" autocomplete="one-time-code" spellcheck="false" value="<?php echo htmlspecialchars($posted_code, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $form_error_key !== '' ? ' aria-invalid="true" aria-describedby="form-error" autofocus' : ' autofocus'; ?> required>
            </p>
            <button type="submit" data-i18n="mfa_verify">Verify</button>
        </form>
        <p><a href="mfa.php?cancel=1" data-i18n="mfa_cancel">Cancel and return to sign in</a></p>
    </section>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>

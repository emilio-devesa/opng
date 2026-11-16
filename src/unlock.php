<?php

declare(strict_types=1);

// unlock.php
// Password prompt for a protected entry. A successful unlock is remembered for
// the whole thread for a short period (10.5).

require_once('includes/session.php');
session_start_secured();
require_once('includes/csrf.php');
require_once('includes/db.php');
require_once('includes/http_input.php');
require_once('includes/error_messages.php');
require_once('includes/assets.php');
require_once('includes/account_role.php');
require_once('includes/ratelimit.php');
require_once('includes/audit_log.php');
require_once('includes/entry_access.php');

$asset_version = opng_asset_version();

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$id = http_string_parameter($_GET, 'id', 255, false);
if ($id === null || !http_valid_entry_id($id)) {
    opng_error_redirect('error_invalid_id');
}

$user_id = http_positive_integer($_SESSION['user_id'] ?? null);
$role = is_string($_SESSION['role'] ?? null) ? $_SESSION['role'] : 'guest';

$db = db_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable');
}

try {
    $stmt = $db->prepare(
        'SELECT id, user_id, root_id, deleted_at, expires_at, visibility, protection_hash, topic
         FROM entries WHERE id = ?'
    );
    $stmt->bind_param('s', $id);
    if (!$stmt->execute()) {
        throw new RuntimeException('Entry lookup query failed.');
    }
    $result = $stmt->get_result();
    $entry = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();
    $role = refresh_account_role($db) ?? 'guest';
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log('OPNG unlock.php: ' . $exception->getMessage());
    $db->close();
    opng_error_redirect('error_database_operation');
}

if (
    !is_array($entry)
    || entry_is_deleted($entry)
    || entry_is_expired($entry)
    || !entry_visibility_allows($entry, $user_id, (string) $role)
) {
    $db->close();
    opng_error_redirect('error_entry_not_found');
}

$root_id = entry_thread_root_id($entry);
if (!entry_requires_unlock($entry) || entry_is_unlocked($root_id)) {
    $db->close();
    header('Location: view.php?id=' . urlencode($id));
    exit;
}

$form_error_key = '';
$status = 200;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $db->close();
        opng_error_redirect('error_csrf');
    }
    $password = http_string_parameter($_POST, 'protection_password', 72, false);
    if ($password === null || $password === '') {
        $form_error_key = 'error_protection_password';
    } else {
        $limit = rate_limit_hit($db, 'entry_unlock', $id . '|' . client_ip(), 10, 900);
        if ($limit === RateLimitResult::Unavailable) {
            $db->close();
            opng_error_redirect('error_service_unavailable');
        }
        if ($limit === RateLimitResult::Limited) {
            $db->close();
            opng_error_redirect('error_unlock_rate_limit');
        }

        if (entry_protection_verify($password, (string) $entry['protection_hash'])) {
            rate_limit_clear($db, 'entry_unlock', $id . '|' . client_ip(), 900);
            entry_mark_unlocked($root_id);
            audit_log_record($db, $user_id, 'entry.unlock', $id);
            $db->close();
            header('Location: view.php?id=' . urlencode($id));
            exit;
        }

        $form_error_key = 'error_protection_password_invalid';
    }
    $status = opng_error_status($form_error_key);
}

$db->close();
if ($form_error_key !== '') {
    http_response_code($status);
}

$lang_attr = 'en';
$page_title = 'Protected entry';
$page_title_i18n = 'unlock_title';
$base = '';
require(__DIR__ . '/includes/layout_header.php');
?>
    <section class="auth-page">
        <h1 data-i18n="unlock_title">Protected entry</h1>
        <p data-i18n="unlock_intro">This entry requires a password. Enter it to view the thread.</p>
        <?php if ($form_error_key !== ''): ?>
        <p class="form-error" id="form-error" role="alert" data-i18n="<?php echo htmlspecialchars($form_error_key, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars(opng_error_text($form_error_key), ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <?php endif; ?>
        <form method="post" action="unlock.php?id=<?php echo urlencode($id); ?>" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <p>
                <label for="protection_password" data-i18n="protection_password_label">Password:</label>
                <input id="protection_password" type="password" name="protection_password" maxlength="72" autocomplete="current-password"<?php echo $form_error_key !== '' ? ' aria-invalid="true" aria-describedby="form-error" autofocus' : ' autofocus'; ?> required>
            </p>
            <button type="submit" data-i18n="unlock_submit">Unlock</button>
        </form>
        <p><a href="index.php" data-i18n="return">Return to Home</a></p>
    </section>
<?php require(__DIR__ . '/includes/layout_footer.php'); ?>

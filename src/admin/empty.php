<?php
// (v0.8) Vaciar pastes. Refactor: session/csrf centralizados.
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

$db = database_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable', '../error.php');
}
$session_user_id = require_admin($db, 'empty.php');

// Verificar que la solicitud es POST
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $db->close();
    // Sin POST: mostrar la página de confirmación
    $csrf_token = csrf_token();
    $lang_attr = 'en';
    $page_title = 'Admin - Empty database';
    $page_title_i18n = 'empty_title';
    $base = '../';
    require(__DIR__ . "/../includes/layout_header.php");
    ?>
            <h1 data-i18n="empty_title">⚠️ Empty database</h1>
            <p><strong data-i18n="empty_warning">This action will permanently delete all stored pastes.</strong></p>
            <p data-i18n="empty_confirm">Are you sure you want to continue?</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <label for="empty_current_password" data-i18n="reauth_password">Current password:</label>
                <input id="empty_current_password" type="password" name="current_password" maxlength="72" autocomplete="current-password" required>
                <button type="submit" class="danger" data-i18n="empty_yes">Yes, empty it</button>
            </form>
            <a href="admin.php" data-i18n="cancel">Cancel</a>
    <?php
    require(__DIR__ . "/../includes/layout_footer.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

// Verificar token CSRF
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    opng_error_redirect('error_csrf', '../error.php');
}

// Emptying the database is irreversible: require the current password even
// when the CSRF token and the JavaScript confirmation are present (SEC-07).
$current_password = http_string_parameter($_POST, 'current_password', 72, false);
if (!reauthenticate_user($db, $session_user_id, $current_password, 'admin_reauth_empty')) {
    $db->close();
    opng_error_redirect('error_reauth', '../error.php');
}

// DELETE works with the runtime DML account; TRUNCATE would require DROP privileges.
$transaction_started = false;
try {
    $db->begin_transaction();
    $transaction_started = true;
    if (!$db->query("DELETE FROM entries")) {
        throw new RuntimeException('Entries could not be removed.');
    }
    if (!global_quota_zero($db)) {
        throw new RuntimeException('Global storage counter could not be reset.');
    }
    audit_log_record($db, $session_user_id, 'database.empty', 'entries');
    if (!$db->commit()) {
        throw new RuntimeException('Empty-database transaction could not be committed.');
    }
    $transaction_started = false;
    admin_metrics_invalidate($db);
    header("Location: ../index.php?msg=" . urlencode("All pastes have been deleted"));
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log("OPNG empty.php: failed to delete entries: " . $exception->getMessage());
    if ($transaction_started) {
        $db->rollback();
    }
    opng_error_redirect('error_empty_pastes', '../error.php');
}

$db->close();
exit;

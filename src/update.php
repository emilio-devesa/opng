<?php
// (v0.8) Edición REAL de un paste (UPDATE), nuevo flujo del hallazgo U1.
// Solo el autor del paste o un administrador pueden hacerlo.
require("includes/session.php");
session_start_secured();
require("includes/db.php");
require("includes/csrf.php");
require("includes/rules.php");
require("includes/paste_validation.php");
require("includes/paste_permissions.php");
require("includes/user_quota.php");
require("includes/http_input.php");
require("includes/account_role.php");
require("includes/error_messages.php");

// ------------------ Validaciones de seguridad ------------------
if (
    !isset($_SESSION['user_id'])
    || http_positive_integer($_SESSION['user_id']) === null
) {
    opng_error_redirect('error_sign_in_to_edit');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    opng_error_redirect('error_csrf');
}

// El ID debe ser alfanumérico (mismo formato que genera submit.php)
$id = http_string_parameter($_POST, 'input_ID', 255, false);
if ($id === null || !http_valid_entry_id($id)) {
    opng_error_redirect('error_invalid_id');
}

// Validación de campos
// Preserve the exact text, including leading indentation and trailing newlines.
$text = http_string_parameter($_POST, 'input_text', OPNG_MAX_PASTE_BYTES + 1, false);
$topic = http_string_parameter($_POST, 'input_topic', OPNG_MAX_TOPIC_BYTES + 1);
$language = http_string_parameter($_POST, 'input_language', 255);
if ($text === null || $topic === null || $language === null) {
    opng_error_redirect('error_invalid_paste');
}

// Valores
$validation_error = paste_validation_error($text, $topic);
if ($validation_error !== null) {
    opng_error_redirect($validation_error);
}

// Lenguaje: allowlist (igual que en submit.php)
try {
    $rules = load_rules();
} catch (RuntimeException $exception) {
    opng_error_redirect('error_language_config');
}
if (!isset($rules[$language])) {
    opng_error_redirect('error_invalid_language');
}

// Conectar a la base de datos
$db = db_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable');
}

$transaction_started = false;
try {
    $db->begin_transaction();
    $transaction_started = true;
    $stmt = $db->prepare("SELECT id, user_id, OCTET_LENGTH(text) AS text_bytes FROM entries WHERE id = ? FOR UPDATE");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result) {
        throw new RuntimeException('Entry lookup result is unavailable.');
    }
    $entry = $result->fetch_assoc();
    $result->free();
    $stmt->close();
    $stmt = null;

    if (!$entry) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_entry_not_found');
    }

    // Comprobar permisos: admin o propietario del paste
    $user_role = refresh_account_role($db) ?? 'guest';
    $owner_id   = $entry['user_id'];
    $current_id = $_SESSION['user_id'];

    $current_user_id = http_positive_integer($current_id);
    if ($current_user_id === null) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_sign_in_to_edit');
    }
    $entry_owner_id = $owner_id !== null ? (int) $owner_id : null;
    if (!paste_owner_can_manage($current_user_id, (string) $user_role, $entry_owner_id)) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_permission');
    }

    $previous_bytes = (int) ($entry['text_bytes'] ?? 0);
    $replacement_bytes = strlen($text);
    $global_delta = $replacement_bytes - $previous_bytes;

    // Same transactional counter as submit.php: an edit can grow a paste past
    // the global cap even when the per-account quota allows it (SEC-04/ROB-03).
    $global_used = global_quota_lock($db);
    if ($global_used === null) {
        error_log('OPNG update.php: global storage counter is unavailable');
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_database_operation');
    }
    if ($global_delta > 0 && !global_quota_allows($global_used, $global_delta)) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_global_storage_quota');
    }

    if ($owner_id !== null) {
        $usage = lock_user_quota($db, (int) $owner_id);
        if ($usage === null) {
            error_log('OPNG update.php: paste owner was not found while checking storage quota');
            $db->rollback();
            $transaction_started = false;
            $db->close();
            opng_error_redirect('error_database_operation');
        }
        if (!user_quota_allows($usage, $previous_bytes, $replacement_bytes)) {
            $db->rollback();
            $transaction_started = false;
            $db->close();
            opng_error_redirect('error_storage_quota');
        }
    }

    $stmt = $db->prepare("UPDATE entries SET language = ?, text = ?, topic = ? WHERE id = ?");
    $stmt->bind_param("ssss", $language, $text, $topic, $id);
    $ok = $stmt->execute();
    $stmt->close();
    $stmt = null;
    if (!$ok) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_update_paste');
    }

    if ($global_delta !== 0 && !global_quota_consume($db, $global_delta)) {
        error_log('OPNG update.php: could not update the global storage counter');
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_update_paste');
    }
    if (!$db->commit()) {
        error_log("OPNG update.php: failed to commit the paste transaction");
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_update_paste');
    }
    $transaction_started = false;
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log("OPNG update.php: " . $exception->getMessage());
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if ($transaction_started) {
        $db->rollback();
    }
    $db->close();
    opng_error_redirect('error_database_operation');
}
$db->close();

header("Location: view.php?id=" . urlencode($id) . "&msg=" . urlencode("Paste updated successfully"));
exit;
?>

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

// ------------------ Validaciones de seguridad ------------------
if (
    !isset($_SESSION['user_id'])
    || (!is_int($_SESSION['user_id']) && !is_string($_SESSION['user_id']))
    || (is_string($_SESSION['user_id']) && !ctype_digit($_SESSION['user_id']))
) {
    header("Location: error.php?msg=" . urlencode("You must be signed in to edit a paste."));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: error.php?msg=" . urlencode("Request method not allowed."));
    exit;
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    header("Location: error.php?msg=" . urlencode("Error: invalid CSRF token."));
    exit;
}

// El ID debe ser alfanumérico (mismo formato que genera submit.php)
if (
    !isset($_POST['input_ID'])
    || !is_string($_POST['input_ID'])
    || $_POST['input_ID'] === ''
    || !ctype_alnum($_POST['input_ID'])
) {
    header("Location: error.php?msg=" . urlencode("Invalid ID"));
    exit;
}
$id = trim($_POST['input_ID']);

// Validación de campos
if (
    !isset($_POST['input_text'], $_POST['input_language'], $_POST['input_topic'])
    || !is_string($_POST['input_text'])
    || !is_string($_POST['input_language'])
    || !is_string($_POST['input_topic'])
) {
    header("Location: error.php?msg=" . urlencode("Invalid paste data."));
    exit;
}

// Valores
$text = trim($_POST['input_text']);
$topic = trim($_POST['input_topic']);
$validation_error = paste_validation_error($text, $topic);
if ($validation_error !== null) {
    header("Location: error.php?msg=" . urlencode($validation_error));
    exit;
}

// Lenguaje: allowlist (igual que en submit.php)
try {
    $rules = load_rules();
} catch (RuntimeException $exception) {
    header("Location: error.php?msg=" . urlencode("Language configuration is unavailable."));
    exit;
}
$language = trim($_POST['input_language']);
if (!isset($rules[$language])) {
    header("Location: error.php?msg=" . urlencode("Error: The selected language ('" . $language . "') is not valid."));
    exit;
}

// Conectar a la base de datos
$db = db_connect();
if (!$db) {
    header("Location: error.php?msg=" . urlencode("Database connection error."));
    exit;
}

$transaction_started = false;
try {
    $db->begin_transaction();
    $transaction_started = true;
    $stmt = $db->prepare("SELECT id, user_id, OCTET_LENGTH(text) AS text_bytes FROM entries WHERE id = ? FOR UPDATE");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$entry) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        header("Location: error.php?msg=" . urlencode("Entry not found"));
        exit;
    }

    // Comprobar permisos: admin o propietario del paste
    $user_role  = $_SESSION['role'] ?? 'guest';
    $user_role = is_string($user_role) ? $user_role : 'guest';
    $owner_id   = $entry['user_id'];
    $current_id = $_SESSION['user_id'];

    $current_user_id = (int) $current_id;
    $entry_owner_id = $owner_id !== null ? (int) $owner_id : null;
    if (!paste_owner_can_manage($current_user_id, (string) $user_role, $entry_owner_id)) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        header("Location: error.php?msg=" . urlencode("You don't have permission to edit this paste."));
        exit;
    }

    if ($owner_id !== null) {
        $usage = lock_user_quota($db, (int) $owner_id);
        if ($usage === null) {
            error_log('OPNG update.php: paste owner was not found while checking storage quota');
            $db->rollback();
            $transaction_started = false;
            $db->close();
            header("Location: error.php?msg=" . urlencode("Database operation failed."));
            exit;
        }
        if (!user_quota_allows($usage, (int) ($entry['text_bytes'] ?? 0), strlen($text))) {
            $db->rollback();
            $transaction_started = false;
            $db->close();
            header("Location: error.php?msg=" . urlencode("Your account storage limit of 100 MiB has been reached."));
            exit;
        }
    }

    $stmt = $db->prepare("UPDATE entries SET language = ?, text = ?, topic = ? WHERE id = ?");
    $stmt->bind_param("ssss", $language, $text, $topic, $id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        header("Location: error.php?msg=" . urlencode("Error updating the paste."));
        exit;
    }
    if (!$db->commit()) {
        error_log("OPNG update.php: failed to commit the paste transaction");
        $db->rollback();
        $transaction_started = false;
        $db->close();
        header("Location: error.php?msg=" . urlencode("Error updating the paste."));
        exit;
    }
    $transaction_started = false;
} catch (mysqli_sql_exception $exception) {
    error_log("OPNG update.php: " . $exception->getMessage());
    if ($transaction_started) {
        $db->rollback();
    }
    $db->close();
    header("Location: error.php?msg=" . urlencode("Database operation failed."));
    exit;
}
$db->close();

header("Location: view.php?id=" . urlencode($id) . "&msg=" . urlencode("Paste updated successfully"));
exit;
?>

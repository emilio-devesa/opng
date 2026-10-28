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

// ------------------ Validaciones de seguridad ------------------
if (
    !isset($_SESSION['user_id'])
    || http_positive_integer($_SESSION['user_id']) === null
) {
    header("Location: error.php?msg=" . urlencode("You must be signed in to edit a paste."));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    header("Location: error.php?msg=" . urlencode("Error: invalid CSRF token."));
    exit;
}

// El ID debe ser alfanumérico (mismo formato que genera submit.php)
$id = http_string_parameter($_POST, 'input_ID', 255, false);
if ($id === null || !http_valid_entry_id($id)) {
    header("Location: error.php?msg=" . urlencode("Invalid ID"));
    exit;
}

// Validación de campos
$text = http_string_parameter($_POST, 'input_text', OPNG_MAX_PASTE_BYTES + 1);
$topic = http_string_parameter($_POST, 'input_topic', OPNG_MAX_TOPIC_BYTES + 1);
$language = http_string_parameter($_POST, 'input_language', 255);
if ($text === null || $topic === null || $language === null) {
    header("Location: error.php?msg=" . urlencode("Invalid paste data."));
    exit;
}

// Valores
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
if (!isset($rules[$language])) {
    header("Location: error.php?msg=" . urlencode("Invalid language."));
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
        header("Location: error.php?msg=" . urlencode("Entry not found"));
        exit;
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
        header("Location: error.php?msg=" . urlencode("You must be signed in to edit a paste."));
        exit;
    }
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
    $stmt = null;
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
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log("OPNG update.php: " . $exception->getMessage());
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
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

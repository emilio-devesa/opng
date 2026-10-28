<?php
// (v0.8) Edición REAL de un paste (UPDATE), nuevo flujo del hallazgo U1.
// Solo el autor del paste o un administrador pueden hacerlo.
require("includes/session.php");
session_start_secured();
require("includes/db.php");
require("includes/csrf.php");
require("includes/rules.php");
require("includes/paste_validation.php");

// ------------------ Validaciones de seguridad ------------------
if (!isset($_SESSION['user_id'])) {
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
$rules = load_rules();
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

// Localizar el paste y su propietario
try {
    $stmt = $db->prepare("SELECT id, user_id FROM entries WHERE id = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (mysqli_sql_exception $exception) {
    error_log("OPNG update.php: " . $exception->getMessage());
    $db->close();
    header("Location: error.php?msg=" . urlencode("Database operation failed."));
    exit;
}

if (!$entry) {
    $db->close();
    header("Location: error.php?msg=" . urlencode("Entry not found"));
    exit;
}

// Comprobar permisos: admin o propietario del paste
$user_role  = $_SESSION['role'] ?? 'guest';
$owner_id   = $entry['user_id'];
$current_id = $_SESSION['user_id'];

$is_owner = ($owner_id !== null && (int)$current_id === (int)$owner_id); // fix R2 (cast explícito)
if ($user_role !== 'admin' && !$is_owner) {
    $db->close();
    header("Location: error.php?msg=" . urlencode("You don't have permission to edit this paste."));
    exit;
}

// Ejecutar el UPDATE
$ok = false;
try {
    $stmt = $db->prepare("UPDATE entries SET language = ?, text = ?, topic = ? WHERE id = ?");
    $stmt->bind_param("ssss", $language, $text, $topic, $id);
    $ok = $stmt->execute();
    $stmt->close();
} catch (mysqli_sql_exception $exception) {
    error_log("OPNG update.php: " . $exception->getMessage());
    $db->close();
    header("Location: error.php?msg=" . urlencode("Error updating the paste."));
    exit;
}
$db->close();

if ($ok) {
    header("Location: view.php?id=" . urlencode($id) . "&msg=" . urlencode("Paste updated successfully"));
} else {
    header("Location: error.php?msg=" . urlencode("Error updating the paste"));
}
exit;
?>

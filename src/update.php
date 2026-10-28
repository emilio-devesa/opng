<?php
// (v0.8) Edición REAL de un paste (UPDATE), nuevo flujo del hallazgo U1.
// Solo el autor del paste o un administrador pueden hacerlo.
require("includes/session.php");
session_start_secured();
require("includes/db.php");
require("includes/csrf.php");
require("includes/rules.php");

$errors = [];

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
if (empty($_POST['input_ID']) || !ctype_alnum($_POST['input_ID'])) {
    header("Location: error.php?msg=" . urlencode("Invalid ID"));
    exit;
}
$id = trim($_POST['input_ID']);

// Validación de campos
if (empty($_POST['input_text'])) {
    $errors[] = "The text cannot be empty.";
}
if (empty($_POST['input_language'])) {
    $errors[] = "The language is required.";
}
if (empty($_POST['input_topic'])) {
    $errors[] = "The topic is required.";
}
if (!empty($errors)) {
    header("Location: error.php?msg=" . urlencode(implode(" ", $errors)));
    exit;
}

// Valores
$text = trim($_POST['input_text']);
$topic = trim($_POST['input_topic']);

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
$stmt = $db->prepare("SELECT id, user_id FROM entries WHERE id = ?");
$stmt->bind_param("s", $id);
$stmt->execute();
$entry = $stmt->get_result()->fetch_assoc();
$stmt->close();

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
$stmt = $db->prepare("UPDATE entries SET language = ?, text = ?, topic = ? WHERE id = ?");
if (!$stmt) {
    $db->close();
    header("Location: error.php?msg=" . urlencode("Error preparing the update query."));
    exit;
}
$stmt->bind_param("ssss", $language, $text, $topic, $id);
$stmt->execute();
$ok = ($stmt->affected_rows >= 0); // 0 filas afectadas no es fallo (mismo contenido)
$stmt->close();
$db->close();

if ($ok) {
    header("Location: view.php?id=" . urlencode($id) . "&msg=" . urlencode("Paste updated successfully"));
} else {
    header("Location: error.php?msg=" . urlencode("Error updating the paste"));
}
exit;
?>

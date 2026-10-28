<?php
// (v0.8) Borrado de un paste. Refactor: session/csrf centralizados.
require_once('../includes/session.php');
session_start_secured();
require_once('../includes/csrf.php');
require_once('../database.php');

// Verificar si el usuario está autenticado
if (!isset($_SESSION['user_id'])) {
    header("Location: ../error.php?msg=" . urlencode("Unauthorized access"));
    exit;
}

// Verificar que la solicitud es POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../error.php?msg=" . urlencode("Request method not allowed."));
    exit;
}

// Verificar token CSRF
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    header("Location: ../error.php?msg=" . urlencode("Error: invalid CSRF token."));
    exit;
}

// Validar el ID proporcionado
if (
    !isset($_POST['input_ID'])
    || !is_string($_POST['input_ID'])
    || $_POST['input_ID'] === ''
    || !ctype_alnum($_POST['input_ID'])
) {
    header("Location: ../error.php?msg=" . urlencode("Invalid ID"));
    exit;
}
$input_ID = trim($_POST['input_ID']);

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user'; // "admin" o "user"

// Conectar a la base de datos
$db = database_connect();
if (!$db) {
    header("Location: ../error.php?msg=" . urlencode("Database connection error."));
    exit;
}

// Si el usuario es administrador, puede eliminar cualquier paste
if ($user_role === "admin") {
    $stmt = $db->prepare("DELETE FROM entries WHERE id = ?");
    if (!$stmt) {
        $db->close();
        header("Location: ../error.php?msg=" . urlencode("Error preparing the query."));
        exit;
    }
    $stmt->bind_param("s", $input_ID);
} else {
    // (R2) Un usuario solo puede eliminar SUS propios pastes.
    // Antes también se permitía borrar pastes sin dueño (user_id IS NULL),
    // lo que dejaba a cualquier registrado borrar pastes anónimos ajenos.
    $stmt = $db->prepare("DELETE FROM entries WHERE id = ? AND user_id = ?");
    if (!$stmt) {
        $db->close();
        header("Location: ../error.php?msg=" . urlencode("Error preparing the query."));
        exit;
    }
    $stmt->bind_param("si", $input_ID, $user_id);
}

$stmt->execute();

// Verificar si la eliminación fue exitosa
if ($stmt->affected_rows === 1) {
    $stmt->close();
    $db->close();
    header("Location: ../index.php?msg=" . urlencode("Paste deleted successfully"));
    exit;
}

$stmt->close();
$db->close();
header("Location: ../error.php?msg=" . urlencode("You don't have permission to delete this paste"));
exit;

<?php
// (v0.8) Borrado de un paste. Refactor: session/csrf centralizados.
require_once('../includes/session.php');
session_start_secured();
require_once('../includes/csrf.php');
require_once('../database.php');
require_once('../includes/http_input.php');

// Verificar si el usuario está autenticado
if (!isset($_SESSION['user_id'])) {
    header("Location: ../error.php?msg=" . urlencode("Unauthorized access"));
    exit;
}

// Verificar que la solicitud es POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// Verificar token CSRF
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    header("Location: ../error.php?msg=" . urlencode("Error: invalid CSRF token."));
    exit;
}

// Validar el ID proporcionado
$input_ID = http_string_parameter($_POST, 'input_ID', 255, false);
if ($input_ID === null || !http_valid_entry_id($input_ID)) {
    header("Location: ../error.php?msg=" . urlencode("Invalid ID"));
    exit;
}

$user_id = http_positive_integer($_SESSION['user_id'] ?? null);
if ($user_id === null) {
    header("Location: ../error.php?msg=" . urlencode("Unauthorized access"));
    exit;
}
$user_role = $_SESSION['role'] ?? 'user'; // "admin" o "user"
if (!is_string($user_role) || !in_array($user_role, ['admin', 'user'], true)) {
    header("Location: ../error.php?msg=" . urlencode("Unauthorized access"));
    exit;
}

// Conectar a la base de datos
$db = database_connect();
if (!$db) {
    header("Location: ../error.php?msg=" . urlencode("Database connection error."));
    exit;
}

try {
    // Si el usuario es administrador, puede eliminar cualquier paste.
    if ($user_role === "admin") {
        $stmt = $db->prepare("DELETE FROM entries WHERE id = ?");
        $stmt->bind_param("s", $input_ID);
    } else {
        $stmt = $db->prepare("DELETE FROM entries WHERE id = ? AND user_id = ?");
        $stmt->bind_param("si", $input_ID, $user_id);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Entry deletion query failed.');
    }
    $deleted = $stmt->affected_rows === 1;
    $stmt->close();
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log("OPNG drop_id.php: " . $exception->getMessage());
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $db->close();
    header("Location: ../error.php?msg=" . urlencode("Database operation failed."));
    exit;
}

$db->close();
if ($deleted) {
    header("Location: ../index.php?msg=" . urlencode("Paste deleted successfully"));
    exit;
}

header("Location: ../error.php?msg=" . urlencode("You don't have permission to delete this paste"));
exit;

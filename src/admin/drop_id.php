<?php
session_start();
require_once('../config.php');
require_once('../database.php');

// Verificar si el usuario está autenticado
if (!isset($_SESSION['user_id'])) {
    header("Location: ../error.php?msg=" . urlencode("Acceso no autorizado"));
    exit;
}

// Verificar que la solicitud es POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("<p style='color: red;'>Error: Método de solicitud no permitido.</p>");
}

// Verificar token CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("<p style='color: red;'>Error: Token CSRF inválido.</p>");
}

// Validar el ID proporcionado
if (empty($_POST['input_ID']) || !ctype_alnum($_POST['input_ID'])) {
    header("Location: ../error.php?msg=" . urlencode("ID no válido"));
    exit;
}
$input_ID = trim($_POST['input_ID']);

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role']; // "admin" o "user"

// Conectar a la base de datos
$db = database_connect();

// Si el usuario es administrador, puede eliminar cualquier paste
if ($user_role === "admin") {
    $stmt = $db->prepare("DELETE FROM entries WHERE id = ?");
    $stmt->bind_param("s", $input_ID);
} else {
    // Permitir eliminar solo si el paste pertenece al usuario o no tiene dueño
    $stmt = $db->prepare("DELETE FROM entries WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
    $stmt->bind_param("si", $input_ID, $user_id);
}

if (!$stmt) {
    die("<p>Error en la consulta.</p>");
}
$stmt->execute();

// Verificar si la eliminación fue exitosa
if ($stmt->affected_rows === 1) {
    header("Location: ../index.php?msg=" . urlencode("Paste eliminado correctamente"));
} else {
    header("Location: ../error.php?msg=" . urlencode("No tienes permiso para eliminar este paste"));
}

// Cerrar la conexión
$stmt->close();
$db->close();
?>
<p><a href="admin.php">Volver al panel de administración</a></p>

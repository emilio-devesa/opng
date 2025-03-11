<?php
session_start();
require_once('login.php'); // Verifica autenticación antes de realizar acciones críticas
require_once('../config.php');
require_once('../database.php');

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
    die("<p style='color: red;'>Error: ID inválido.</p>");
}

$input_ID = trim($_POST['input_ID']);

// Conectar a la base de datos
$conn = database_connect();

// Preparar la consulta para eliminar la entrada por ID
$stmt = $conn->prepare("DELETE FROM Entries WHERE ID = ?");
if (!$stmt) {
    die("<p style='color: red;'>Error en la preparación de la consulta: " . $conn->error . "</p>");
}

// Asociar el ID al parámetro y ejecutar la consulta
$stmt->bind_param("s", $input_ID);
$stmt->execute();

// Verificar si la eliminación fue exitosa
if ($stmt->affected_rows > 0) {
    echo "<p style='color: green;'>Entrada con ID <strong>$input_ID</strong> eliminada correctamente.</p>";
} else {
    echo "<p style='color: orange;'>No se encontró ninguna entrada con el ID <strong>$input_ID</strong>.</p>";
}

// Cerrar la conexión
$stmt->close();
$conn->close();
?>
<p><a href="admin.php">Volver al panel de administración</a></p>

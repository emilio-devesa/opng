<?php
require_once('../config.php');
require_once('../login.php'); // Verifica que el usuario esté autenticado
require_once('../database.php');
session_start();

// Verificar token CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token.");
    }

    // Verificar que se envió un ID
    if (!isset($_POST['input_ID']) || empty($_POST['input_ID'])) {
        die("ID not provided.");
    }

    // Conectar a la base de datos usando MySQLi
    $conn = database_connect(); // Usando una función que retorna el objeto MySQLi

    // Preparar y ejecutar la eliminación de la entrada por ID usando consultas preparadas
    $stmt = $conn->prepare("DELETE FROM Entries WHERE ID = ?");
    if ($stmt === false) {
        die("Error preparando la consulta: " . $conn->error);
    }

    // Asociar el ID al parámetro
    $input_ID = $_POST['input_ID'];
    $stmt->bind_param("s", $input_ID);

    // Ejecutar la consulta
    if ($stmt->execute()) {
        if ($stmt->affected_rows === 1) {
            echo "<p>Entry with ID $input_ID deleted successfully.</p>";
        } else {
            echo "<p>No entry found with ID $input_ID.</p>";
        }
    } else {
        echo "<p>Error removing entry: " . $stmt->error . "</p>";
    }

    // Cerrar la conexión
    $stmt->close();
    $conn->close();
} else {
    die("Invalid request method.");
}
?>
<?php
session_start();
require("../config.php");
require("../database.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST["email"]);
    $new_password = $_POST["new_password"];

    if (empty($email) || empty($new_password)) {
        die("Todos los campos son obligatorios.");
    }

    $db = database_connect();

    // Verificar si el usuario existe
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows === 0) {
        die("No se encontró ninguna cuenta con ese email.");
    }

    // Generar nuevo hash de contraseña
    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

    // Actualizar la contraseña en la base de datos
    $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
    $stmt->bind_param("ss", $hashed_password, $email);

    if ($stmt->execute()) {
        echo "Tu contraseña ha sido restablecida.";
    } else {
        echo "Error al actualizar la contraseña.";
    }

    $stmt->close();
    $db->close();
}
?>

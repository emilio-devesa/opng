<?php
session_start();
require("../config.php");
require("../database.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    // Conectar a la BD
    $db = database_connect();

    // Buscar el usuario
    $stmt = $db->prepare("SELECT id, username, password_hash FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows == 1) {
        $stmt->bind_result($id, $username, $passwordHash);
        $stmt->fetch();

        if (password_verify($password, $passwordHash)) {
            $_SESSION["user_id"] = $id;
            $_SESSION["username"] = $username;
            header("Location: ../index.php"); // Redirigir al usuario
            exit;
        } else {
            echo "Contraseña incorrecta.";
        }
    } else {
        echo "Usuario no encontrado.";
    }
    $stmt->close();
    $db->close();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Iniciar Sesión</title>
</head>
<body>
    <h2>Iniciar Sesión</h2>
    <form method="POST">
        <label>Email:</label>
        <input type="email" name="email" required><br>
        <label>Contraseña:</label>
        <input type="password" name="password" required><br>
        <button type="submit">Iniciar sesión</button>
    </form>
</body>
</html>

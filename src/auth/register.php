<?php
session_start();
require("../config.php");
require("../database.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST["username"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    // CAPTCHA Validation
    $captcha_answer = trim($_POST["captcha_answer"]);
    if (!isset($_SESSION['captcha']) || strtoupper($captcha_answer) !== $_SESSION['captcha']) {
        die("Incorrect CAPTCHA. Try again.");
    }
    unset($_SESSION['captcha']); // Evita reutilización del CAPTCHA

    // Validación básica
    if (empty($username) || empty($email) || empty($password)) {
        die("Todos los campos son obligatorios.");
    }

    // Conectar a la BD
    $db = database_connect();

    // Verificar si el usuario ya existe
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        die("El email ya está registrado.");
    }
    $stmt->close();

    // Hash de la contraseña
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    // Insertar usuario
    $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'user')");
    $stmt->bind_param("sss", $username, $email, $passwordHash);
    if ($stmt->execute()) {
        echo "Registro exitoso. <a href='login.php'>Iniciar sesión</a>";
    } else {
        echo "Error en el registro.";
    }
    $stmt->close();
    $db->close();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <h2>Registro</h2>
    <form method="POST">
        <p><label>Usuario:</label>
        <input type="text" name="username" required><br>
        <label>Email:</label>
        <input type="email" name="email" required><br>
        <label>Contraseña:</label>
        <input type="password" name="password" required></p>
        <!-- Imagen CAPTCHA -->
        <p><label>Please enter captcha text:</label>
        <input type="text" name="captcha_answer" required><br>
        <img src="captcha.php" alt="CAPTCHA" class="captcha-image"><br>
        <button type="button" class="refresh-captcha">🔄 Refresh</button></p>
        <br>
        <p><button type="submit">Registrarse</button></p>
    </form>
    <script src="../assets/js/refresh-captcha.js"></script>
</body>
</html>

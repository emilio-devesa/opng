<?php
session_start();
require("../config.php");
require("../database.php");

$message = ""; // Variable para almacenar el mensaje de respuesta

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"]);

    $db = database_connect();
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($user_id);
        $stmt->fetch();
        $stmt->close();

        // Generar token
        $token = bin2hex(random_bytes(32));
        
        // Guardar en la BD
        $stmt = $db->prepare("UPDATE users SET reset_token = ?, reset_token_expires = NOW() + INTERVAL 10 MINUTE WHERE id = ?");
        $stmt->bind_param("si", $token, $user_id);
        $stmt->execute();
        $stmt->close();
        $db->close();

        // Generar enlace de restablecimiento
        $reset_link = "/auth/reset_password.php?token=" . urlencode($token);
        $message = "<p><strong>Token generado:</strong> " . htmlspecialchars($token) . "</p>
                    <p><a href='" . htmlspecialchars($reset_link) . "'>Haz clic aquí para restablecer tu contraseña</a></p>
                    <p><strong>Nota:</strong> Este enlace solo es válido por 10 minutos.</p>";
    } else {
        $message = "<p style='color: red;'>No se encontró una cuenta con este correo.</p>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Request Password Reset</title>
    <style>
        #reset-form { display: <?php echo $message ? 'none' : 'block'; ?>; }
        #message { display: <?php echo $message ? 'block' : 'none'; ?>; }
    </style>
</head>
<body>
    <h2>Request Password Reset</h2>

    <div id="message"><?php echo $message; ?></div>

    <form method="POST" id="reset-form">
        <label>Email:</label>
        <input type="email" name="email" required>
        <button type="submit">Send Reset Link</button>
    </form>
</body>
</html>

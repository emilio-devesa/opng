<?php
session_start();
require("../config.php");
require("../database.php");

$token = "";
$error_message = "";
$show_form = false; // Controla si se debe mostrar el formulario

if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["token"])) {
    $token = trim($_GET["token"]);

    $db = database_connect();

    // Verificar si el token es válido y no ha expirado
    $stmt = $db->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($user_id);
        $stmt->fetch();
        $show_form = true; // Mostrar el formulario si el token es válido
    } else {
        $error_message = "<p style='color: red;'>Invalid or expired token.</p>";
    }

    $stmt->close();
    $db->close();

} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_POST["token"], $_POST["password"])) {
        die("<p style='color: red;'>Invalid request.</p>");
    }

    $token = trim($_POST["token"]);
    $new_password = password_hash($_POST["password"], PASSWORD_BCRYPT);

    // Conectar a la base de datos
    $db = database_connect();

    // Verificar si el token sigue siendo válido
    $stmt = $db->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($user_id);
        $stmt->fetch();
        $stmt->close();

        // Actualizar la contraseña y limpiar el token
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?");
        $stmt->bind_param("si", $new_password, $user_id);
        $stmt->execute();

        echo "<p style='color: green;'>Password updated successfully. You can now <a href='login.php'>log in</a>.</p>";
    } else {
        echo "<p style='color: red;'>Invalid or expired token.</p>";
    }

    $stmt->close();
    $db->close();
    exit; // Salir para evitar mostrar el formulario después de cambiar la contraseña
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
</head>
<body>
    <h2>Reset Password</h2>

    <?php if ($error_message): ?>
        <?php echo $error_message; ?>
    <?php elseif ($show_form): ?>
        <form method="POST">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <label>New Password:</label>
            <input type="password" name="password" required>
            <button type="submit">Change Password</button>
        </form>
    <?php endif; ?>
</body>
</html>

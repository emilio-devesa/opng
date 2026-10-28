<?php
require("../includes/session.php");
session_start_secured();
require("../includes/csrf.php");
require("../database.php");

$message = ""; // Variable para almacenar el mensaje de respuesta

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Protección CSRF
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        header("Location: ../error.php?msg=" . urlencode("Error: invalid CSRF token."));
        exit;
    }

    $email = trim($_POST["email"]);

    $captcha_answer = trim($_POST["captcha_answer"]);
    if (!isset($_SESSION['captcha']) || strtoupper($captcha_answer) !== $_SESSION['captcha']) {
        die("Incorrect CAPTCHA. Try again.");
    }
    unset($_SESSION['captcha']); // Evita reutilización del CAPTCHA

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
        $reset_link = "reset_password.php?token=" . urlencode($token);
        $message = "<p><strong>Token generated:</strong> " . htmlspecialchars($token) . "</p>
                    <p><a href='" . htmlspecialchars($reset_link) . "'>Click here to reset your password</a></p>
                    <p><strong>Note:</strong> This link is only valid for 10 minutes.</p>";
    } else {
        $message = "<p style='color: red;'>No account was found with this email address.</p>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title data-i18n="request_reset_title">Request Password Reset</title>
    <style>
        #reset-form { display: <?php echo $message ? 'none' : 'block'; ?>; }
        #message { display: <?php echo $message ? 'block' : 'none'; ?>; }
    </style>
    <link rel="stylesheet" href="../main.css">
</head>
<body>
    <h2 data-i18n="request_reset_title">Request Password Reset</h2>

    <div id="message"><?php echo $message; ?></div>

    <form method="POST" id="reset-form">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <p><label data-i18n="email">Email:</label>
        <input type="email" name="email" required></p>
        <!-- Imagen CAPTCHA -->
        <p><label data-i18n="captcha_label">Please enter the captcha text:</label>
        <input type="text" name="captcha_answer" required><br>
        <img src="captcha.php" alt="CAPTCHA" class="captcha-image"><br>
        <button type="button" class="refresh-captcha" data-i18n="refresh_captcha">🔄 Refresh</button></p>
        <br>
        <p><button type="submit" data-i18n="send_reset_link">Send Reset Link</button></p>
    </form>
    <script src="../assets/js/refresh-captcha.js"></script>
</body>
</html>

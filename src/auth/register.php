<?php
require("../includes/session.php");
session_start_secured();
require("../includes/csrf.php");
require("../includes/ratelimit.php");
require("../database.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Protección CSRF
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        header("Location: ../error.php?msg=" . urlencode("Error: invalid CSRF token."));
        exit;
    }

    $username = trim($_POST["username"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    // Conectar a la BD
    $db = database_connect();

    // (R8/S5) Rate limit de registro por IP: 5 por hora.
    if (!rate_limit_hit($db, 'register', client_ip(), 5, 3600)) {
        $db->close();
        header("Location: ../error.php?msg=" . urlencode("Too many registration attempts. Please try again later."));
        exit;
    }

    // CAPTCHA Validation
    $captcha_answer = trim($_POST["captcha_answer"]);
    if (!isset($_SESSION['captcha']) || strtoupper($captcha_answer) !== $_SESSION['captcha']) {
        die("Incorrect CAPTCHA. Try again.");
    }
    unset($_SESSION['captcha']); // Evita reutilización del CAPTCHA

    // Validación básica
    if (empty($username) || empty($email) || empty($password)) {
        die("All fields are required.");
    }

    // Verificar si el usuario ya existe
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        die("This email is already registered.");
    }
    $stmt->close();

    // Hash de la contraseña
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    // Insertar usuario
    $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'user')");
    $stmt->bind_param("sss", $username, $email, $passwordHash);
    if ($stmt->execute()) {
        echo "Registration successful. <a href='login.php'>Sign in</a>";
    } else {
        echo "Registration error.";
    }
    $stmt->close();
    $db->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title data-i18n="register_title">Sign up</title>
    <link rel="stylesheet" href="../main.css">
</head>
<body>
    <h2 data-i18n="register_title">Sign up</h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <p><label data-i18n="username">Username:</label>
        <input type="text" name="username" required><br>
        <label data-i18n="email">Email:</label>
        <input type="email" name="email" required><br>
        <label data-i18n="password">Password:</label>
        <input type="password" name="password" required></p>
        <!-- Imagen CAPTCHA -->
        <p><label data-i18n="captcha_label">Please enter the captcha text:</label>
        <input type="text" name="captcha_answer" required><br>
        <img src="captcha.php" alt="CAPTCHA" class="captcha-image"><br>
        <button type="button" class="refresh-captcha" data-i18n="refresh_captcha">🔄 Refresh</button></p>
        <br>
        <p><button type="submit" data-i18n="register">Sign up</button></p>
    </form>
    <script src="../assets/js/refresh-captcha.js"></script>
</body>
</html>

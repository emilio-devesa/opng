<?php
require("../includes/session.php");
session_start_secured();
require("../includes/csrf.php");
require("../includes/ratelimit.php");
require("../includes/password_validation.php");
require("../database.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Protección CSRF
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        header("Location: ../error.php?msg=" . urlencode("Error: invalid CSRF token."));
        exit;
    }

    if (
        !isset($_POST["username"], $_POST["email"], $_POST["password"], $_POST["captcha_answer"])
        || !is_string($_POST["username"])
        || !is_string($_POST["email"])
        || !is_string($_POST["password"])
        || !is_string($_POST["captcha_answer"])
    ) {
        header("Location: ../error.php?msg=" . urlencode("Invalid registration data."));
        exit;
    }

    $username = trim($_POST["username"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    $captcha_answer = strtoupper(trim($_POST["captcha_answer"]));

    $password_error = password_validation_error($password);
    if (
        $username === ''
        || strlen($username) > 50
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($email) > 100
        || $password_error !== null
    ) {
        $validation_message = $password_error ?? "Please check the username and email limits.";
        header("Location: ../error.php?msg=" . urlencode($validation_message));
        exit;
    }

    // Conectar a la BD
    $db = database_connect();
    if (!$db) {
        header("Location: ../error.php?msg=" . urlencode("Database connection error."));
        exit;
    }

    // (R8/S5) Rate limit de registro por IP: 5 por hora.
    if (!rate_limit_hit($db, 'register', client_ip(), 5, 3600)) {
        $db->close();
        header("Location: ../error.php?msg=" . urlencode("Too many registration attempts. Please try again later."));
        exit;
    }

    if (!isset($_SESSION['captcha']) || !is_string($_SESSION['captcha']) ||
        $captcha_answer !== $_SESSION['captcha']) {
        $db->close();
        header("Location: ../error.php?msg=" . urlencode("Incorrect CAPTCHA. Try again."));
        exit;
    }
    unset($_SESSION['captcha']); // Evita reutilización del CAPTCHA

    try {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            $db->close();
            header("Location: ../error.php?msg=" . urlencode("This email is already registered."));
            exit;
        }
        $stmt->close();

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'user')");
        $stmt->bind_param("sss", $username, $email, $passwordHash);
        if (!$stmt->execute()) {
            error_log("OPNG register.php: " . $stmt->error);
            $stmt->close();
            $db->close();
            header("Location: ../error.php?msg=" . urlencode("Registration error."));
            exit;
        }
        $stmt->close();
        $db->close();
        header("Location: login.php?msg=" . urlencode("Registration successful. Please sign in."));
        exit;
    } catch (mysqli_sql_exception $exception) {
        error_log("OPNG register.php: " . $exception->getMessage());
        $db->close();
        header("Location: ../error.php?msg=" . urlencode("Registration service is temporarily unavailable."));
        exit;
    }
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
        <input type="text" name="username" maxlength="50" required><br>
        <label data-i18n="email">Email:</label>
        <input type="email" name="email" maxlength="100" required><br>
        <label data-i18n="password">Password:</label>
        <input type="password" name="password" minlength="12" maxlength="72" required></p>
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

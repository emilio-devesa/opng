<?php
require("../includes/session.php");
session_start_secured();
require("../includes/csrf.php");
require("../includes/ratelimit.php");

require("../database.php");

// Obtener la URL de redirección o establecer index.php como predeterminado
$redirect_url = $_SESSION['redirect_after_login'] ?? '../index.php';

// (S6) Evitar open redirect: solo se aceptan rutas relativas internas
if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $redirect_url)) {
    $redirect_url = '../index.php';
}

$error_message = "";

// Si el usuario ya está autenticado, redirigir a la página de inicio
// (antes se comprobaba $_SESSION['logged_in'], que nunca se establece)
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

if (!isset($_SESSION["login_attempts"])) {
    $_SESSION["login_attempts"] = 0;
    $_SESSION["last_attempt_time"] = time();
}

// Si hay más de 5 intentos en 10 minutos, bloquear
if ($_SESSION["login_attempts"] >= 5 && (time() - $_SESSION["last_attempt_time"] < 600)) {
    header("Location: ../error.php?msg=" . urlencode("Too many login attempts. Please try again in 10 minutes."));
    exit;
}

// Generar un token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verificar si el token CSRF existe y es válido
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        header("Location: ../error.php?msg=" . urlencode("Error: invalid CSRF token."));
        exit;
    }
    
    if (
        !isset($_POST["email"], $_POST["password"])
        || !is_string($_POST["email"])
        || !is_string($_POST["password"])
    ) {
        header("Location: ../error.php?msg=" . urlencode("Invalid email or password."));
        exit;
    }

    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        header("Location: ../error.php?msg=" . urlencode("Invalid email or password."));
        exit;
    }

    // Conectar a la BD
    $db = database_connect();
    if (!$db) {
        header("Location: ../error.php?msg=" . urlencode("Authentication service is temporarily unavailable."));
        exit;
    }

    // (S5) Rate limit persistente por IP + cuenta. El contador de sesión de
    // arriba se puede saltar borrando la cookie; este no.
    if (!rate_limit_hit($db, 'login', client_ip() . '|' . strtolower($email), 5, 600)) {
        $db->close();
        header("Location: ../error.php?msg=" . urlencode("Too many login attempts. Please try again in 10 minutes."));
        exit;
    }

    // Buscar el usuario
    try {
        $stmt = $db->prepare("SELECT id, username, password_hash, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            error_log("OPNG login.php: could not read account lookup result");
            $stmt->close();
            $db->close();
            header("Location: ../error.php?msg=" . urlencode("Authentication service is temporarily unavailable."));
            exit;
        }
        $user = $result->fetch_assoc();
        $stmt->close();
        $db->close();
    } catch (mysqli_sql_exception $exception) {
        error_log("OPNG login.php: " . $exception->getMessage());
        $db->close();
        header("Location: ../error.php?msg=" . urlencode("Authentication service is temporarily unavailable."));
        exit;
    }

    // Perform the same bcrypt check when no account exists to reduce enumeration timing.
    $dummy_password_hash = '$2y$10$ROoOfaJDwIVN2F857mUKXurQvjhmsoCwjxC8a8JO8.vTDLK7q7b5y';
    $password_hash = $user['password_hash'] ?? $dummy_password_hash;
    $password_is_valid = password_verify($password, $password_hash);

    if ($user !== null && $password_is_valid) {
        // Autenticación exitosa, restablecer intentos fallidos
        $_SESSION["login_attempts"] = 0;

        // (S10) Regenerar el ID de sesión para evitar session fixation
        session_regenerate_id(true);

        $_SESSION["user_id"] = $user['id'];
        $_SESSION["username"] = $user['username'];
        $_SESSION["role"] = $user['role'];

        // Redirigir al usuario a la página de origen después del login
        header("Location: " . $redirect_url);

        unset($_SESSION['redirect_after_login']); // Eliminar variable de sesión
        exit;
    }

    // Cualquier camino que llegue aquí es un fallo de autenticación.
    $_SESSION["login_attempts"]++;
    $_SESSION["last_attempt_time"] = time();
    $error_message = "Invalid email or password.";
    // Redirigir ANTES de emitir cualquier salida
    // (fix H3/U5: anteriormente el header() se llamaba dentro del <body>,
    // lo que provocaba "headers already sent" y el error nunca se mostraba)
    header("Location: ../error.php?msg=" . urlencode($error_message));
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title data-i18n="login_title">Sign in</title>
    <link rel="stylesheet" href="../main.css">
</head>
<body>
    <h2 data-i18n="login_title">Sign in</h2>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <label data-i18n="email">Email:</label>
        <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required><br>
        <label data-i18n="password">Password:</label>
        <input type="password" name="password" required><br>
        <button type="submit" data-i18n="login">Sign in</button>
    </form>

    <a href="register.php" data-i18n="no_account">No account? Register here</a>    
</body>
</html>

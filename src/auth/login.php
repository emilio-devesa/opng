<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require("../config.php");
require("../database.php");

// Obtener la URL de redirección o establecer index.php como predeterminado
$redirect_url = isset($_SESSION['redirect_after_login']) ? $_SESSION['redirect_after_login'] : '/index.php';

$error_message = "";

// Si el usuario ya está autenticado, redirigir a la página de inicio
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if (basename($_SERVER['PHP_SELF']) === 'login.php') {
        header('Location: ../index.php');
        exit();
    }
    return; // Evita ejecutar más código en páginas autenticadas
}

if (!isset($_SESSION["login_attempts"])) {
    $_SESSION["login_attempts"] = 0;
    $_SESSION["last_attempt_time"] = time();
}

// Si hay más de 5 intentos en 10 minutos, bloquear
if ($_SESSION["login_attempts"] >= 5 && (time() - $_SESSION["last_attempt_time"] < 600)) {
    die("Demasiados intentos de inicio de sesión. Intenta nuevamente en 10 minutos.");
}

// Generar un token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verificar si el token CSRF existe y es válido
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("<p style='color: red;'>Error: CSRF token inválido.</p>");
    }
    
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
            // Autenticación exitosa, restablecer intentos fallidos
            $_SESSION["login_attempts"] = 0;

            $_SESSION["user_id"] = $id;
            $_SESSION["username"] = $username;
            
            // Redirigir al usuario a la página de origen después del login
            header("Location: " . htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8'));

            unset($_SESSION['redirect_after_login']); // Eliminar variable de sesión
            exit;
        } else {
            $_SESSION["login_attempts"]++;
            $_SESSION["last_attempt_time"] = time();
            $error_message = "Contraseña incorrecta.";
        }
    } else {
        $_SESSION["login_attempts"]++;
        $_SESSION["last_attempt_time"] = time();
        $error_message = "Usuario no encontrado.";
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

    <?php if (!empty($error_message)) {
        header("Location: error.php?msg=" . urlencode(htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8')));
        exit;
    }
    ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <label>Email:</label>
        <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required><br>
        <label>Contraseña:</label>
        <input type="password" name="password" required><br>
        <button type="submit">Iniciar sesión</button>
    </form>

    <a href="register.php">¿No tienes cuenta? Regístrate aquí</a>    
</body>
</html>

<?php
require("../includes/session.php");
session_start_secured();
require("../includes/csrf.php");
require("../includes/ratelimit.php");
require("../includes/http_input.php");

require("../database.php");

if (!in_array($_SERVER["REQUEST_METHOD"], ["GET", "POST"], true)) {
    http_response_code(405);
    header("Allow: GET, POST");
    exit;
}

// Obtener la URL de redirección o establecer index.php como predeterminado
$redirect_url = http_string_parameter($_SESSION, 'redirect_after_login', 2048, false) ?? '../index.php';

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

if (
    !isset($_SESSION["login_attempts"], $_SESSION["last_attempt_time"])
    || !is_int($_SESSION["login_attempts"])
    || !is_int($_SESSION["last_attempt_time"])
    || $_SESSION["login_attempts"] < 0
) {
    $_SESSION["login_attempts"] = 0;
    $_SESSION["last_attempt_time"] = time();
}

// Si hay más de 5 intentos en 10 minutos, bloquear
if ($_SESSION["login_attempts"] >= 5 && (time() - $_SESSION["last_attempt_time"] < 600)) {
    header("Location: ../error.php?msg=" . urlencode("Too many login attempts. Please try again in 10 minutes."));
    exit;
}

$csrf_token = csrf_token();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Verificar si el token CSRF existe y es válido
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        header("Location: ../error.php?msg=" . urlencode("Error: invalid CSRF token."));
        exit;
    }
    
    $email = http_string_parameter($_POST, 'email', 100);
    $password = http_string_parameter($_POST, 'password', 72, false);
    if ($email === null || $password === null) {
        header("Location: ../error.php?msg=" . urlencode("Invalid email or password."));
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
    $stmt = null;
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
        $result->free();
        $stmt->close();
        $stmt = null;
        $db->close();
    } catch (mysqli_sql_exception $exception) {
        error_log("OPNG login.php: " . $exception->getMessage());
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
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
    $notice = http_string_parameter($_GET, 'msg', 128) ?? '';
    $notice_key = $notice === 'Registration successful. Please sign in.' ? 'registration_success' : '';
    $notice = $notice_key !== '' ? $notice : '';
    $lang_attr = 'en';
    $page_title = 'Sign in';
    $page_title_i18n = 'login_title';
    $base = '../';
    require(__DIR__ . '/../includes/layout_header.php');
?>
    <section class="auth-page">
        <h2 data-i18n="login_title">Sign in</h2>
        <?php if ($notice !== ''): ?>
            <p class="flash-message" role="status"<?php echo $notice_key !== '' ? ' data-i18n="' . htmlspecialchars($notice_key, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                <?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?>
            </p>
        <?php endif; ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <p>
                <label for="email" data-i18n="email">Email:</label>
                <input id="email" type="email" name="email" maxlength="100" autocomplete="username" value="<?php echo htmlspecialchars(http_string_parameter($_POST, 'email', 100) ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </p>
            <p>
                <label for="password" data-i18n="password">Password:</label>
                <input id="password" type="password" name="password" maxlength="72" autocomplete="current-password" required>
            </p>
            <button type="submit" data-i18n="login">Sign in</button>
        </form>
        <p><a href="request_reset.php" data-i18n="forgot_password">Forgot your password?</a></p>
        <p><a href="register.php" data-i18n="no_account">No account? Register here</a></p>
    </section>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>

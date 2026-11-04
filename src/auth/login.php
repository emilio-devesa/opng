<?php
require("../includes/session.php");
session_start_secured();
require("../includes/csrf.php");
require("../includes/ratelimit.php");
require("../includes/http_input.php");
require_once("../includes/captcha.php");

require("../database.php");
require("../includes/error_messages.php");
require("../includes/assets.php");

$asset_version = opng_asset_version();

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
    opng_error_redirect('error_login_rate_limit', '../error.php');
}

// Adaptive CAPTCHA: after three failed attempts in this session, require a
// challenge before spending bcrypt work again.
$captcha_required = $_SESSION["login_attempts"] >= 3;

$csrf_token = csrf_token();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Verificar si el token CSRF existe y es válido
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        opng_error_redirect('error_csrf', '../error.php');
    }
    
    $email = http_string_parameter($_POST, 'email', 100);
    $password = http_string_parameter($_POST, 'password', 72, false);
    if ($email === null || $password === null) {
        opng_error_redirect('error_invalid_credentials', '../error.php');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        opng_error_redirect('error_invalid_credentials', '../error.php');
    }

    if ($captcha_required) {
        $captcha_answer = http_string_parameter($_POST, 'captcha_answer', 5);
        if (!captcha_validate($captcha_answer)) {
            opng_error_redirect('captcha_incorrect', '../error.php');
        }
    }

    // Conectar a la BD
    $db = database_connect();
    if (!$db) {
        opng_error_redirect('error_auth_unavailable', '../error.php');
    }

    // (S5/H2) Persistent limits: IP+account, IP-wide and account-wide. The
    // session counter above can be bypassed by deleting the cookie; these
    // rows cannot. They record FAILED attempts only: a successful login must
    // not consume the legitimate user's budget (SEC-03).
    $normalized_email = strtolower($email);
    $login_limits = [
        ['login', client_ip() . '|' . $normalized_email, 5],
        ['login_ip', client_ip(), 30],
        ['login_account', $normalized_email, 10],
    ];

    foreach ($login_limits as [$limit_action, $limit_key, $limit_max]) {
        $limit_result = rate_limit_status($db, $limit_action, $limit_key, $limit_max, 600);
        if ($limit_result === RateLimitResult::Unavailable) {
            $db->close();
            opng_error_redirect('error_service_unavailable', '../error.php');
        }
        if ($limit_result === RateLimitResult::Limited) {
            $db->close();
            opng_error_redirect('error_login_rate_limit', '../error.php');
        }
    }

    // Buscar el usuario
    $stmt = null;
    try {
        $stmt = $db->prepare("SELECT id, username, password_hash, role, session_version FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            error_log("OPNG login.php: could not read account lookup result");
            $stmt->close();
            $db->close();
            opng_error_redirect('error_auth_unavailable', '../error.php');
        }
        $user = $result->fetch_assoc();
        $result->free();
        $stmt->close();
        $stmt = null;
    } catch (mysqli_sql_exception $exception) {
        error_log("OPNG login.php: " . $exception->getMessage());
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        $db->close();
        opng_error_redirect('error_auth_unavailable', '../error.php');
    }

    // Perform the same bcrypt work when no account exists to reduce enumeration timing.
    // Generate the dummy hash lazily with the current PASSWORD_DEFAULT options so
    // it keeps matching new hashes across PHP upgrades.
    $password_hash = $user['password_hash'] ?? password_hash('opng-timing-equalizer', PASSWORD_DEFAULT);
    $password_is_valid = password_verify($password, $password_hash);

    if ($user !== null && $password_is_valid) {
        // Autenticación exitosa, restablecer intentos fallidos
        $_SESSION["login_attempts"] = 0;
        captcha_clear();

        // Upgrade legacy bcrypt hashes (different cost or algorithm) after a
        // successful verification. A rehash failure must not block the login.
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            try {
                $rehash = password_hash($password, PASSWORD_DEFAULT);
                $rehash_stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $rehash_stmt->bind_param('si', $rehash, $user['id']);
                if (!$rehash_stmt->execute()) {
                    error_log('OPNG login.php: password rehash update failed');
                }
                $rehash_stmt->close();
            } catch (mysqli_sql_exception $exception) {
                error_log('OPNG login.php: password rehash failed: ' . $exception->getMessage());
            }
        }

        // A successful login clears the account-scoped buckets; the IP-wide
        // bucket keeps counting failures from other accounts on that address.
        rate_limit_clear($db, 'login', client_ip() . '|' . $normalized_email, 600);
        rate_limit_clear($db, 'login_account', $normalized_email, 600);
        $db->close();

        // (S10) Regenerar el ID de sesión para evitar session fixation
        session_regenerate_id(true);

        $_SESSION["user_id"] = $user['id'];
        $_SESSION["username"] = $user['username'];
        $_SESSION["role"] = $user['role'];
        $_SESSION["session_version"] = (int) ($user['session_version'] ?? 0);
        $_SESSION["opng_authenticated_at"] = time();
        // The pre-login page token must not remain valid after authentication.
        csrf_rotate();

        // Redirigir al usuario a la página de origen después del login
        header("Location: " . $redirect_url);

        unset($_SESSION['redirect_after_login']); // Eliminar variable de sesión
        exit;
    }

    // Cualquier camino que llegue aquí es un fallo de autenticación.
    $_SESSION["login_attempts"]++;
    $_SESSION["last_attempt_time"] = time();
    foreach ($login_limits as [$limit_action, $limit_key, $limit_max]) {
        rate_limit_hit($db, $limit_action, $limit_key, $limit_max, 600);
    }
    $db->close();
    // Redirigir ANTES de emitir cualquier salida
    // (fix H3/U5: anteriormente el header() se llamaba dentro del <body>,
    // lo que provocaba "headers already sent" y el error nunca se mostraba)
    opng_error_redirect('error_invalid_credentials', '../error.php');
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
        <h1 data-i18n="login_title">Sign in</h1>
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
            <?php if ($captcha_required): ?>
            <?php captcha_render('captcha.php'); ?>
            <?php endif; ?>
            <button type="submit" data-i18n="login">Sign in</button>
        </form>
        <p><a href="request_reset.php" data-i18n="forgot_password">Forgot your password?</a></p>
        <p><a href="register.php" data-i18n="no_account">No account? Register here</a></p>
    </section>
    <?php if ($captcha_required): ?>
    <script src="../assets/js/refresh-captcha.js?v=<?php echo $asset_version; ?>"></script>
    <?php endif; ?>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>

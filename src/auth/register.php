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
    $lang_attr = 'en';
    $page_title = 'Sign up';
    $page_title_i18n = 'register_title';
    $base = '../';
    require(__DIR__ . '/../includes/layout_header.php');
?>
    <section class="auth-page">
        <h2 data-i18n="register_title">Sign up</h2>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <p>
                <label for="username" data-i18n="username">Username:</label>
                <input id="username" type="text" name="username" maxlength="50" autocomplete="username" required>
            </p>
            <p>
                <label for="email" data-i18n="email">Email:</label>
                <input id="email" type="email" name="email" maxlength="100" autocomplete="email" required>
            </p>
            <p>
                <label for="password" data-i18n="password">Password:</label>
                <input id="password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-policy" required>
                <span id="password-policy" class="field-help" data-i18n="password_policy">Use a password between 12 and 72 bytes.</span>
            </p>
            <p>
                <label for="captcha_answer" data-i18n="captcha_label">Please enter the captcha text:</label>
                <input id="captcha_answer" type="text" name="captcha_answer" autocomplete="off" required>
                <img src="captcha.php" alt="CAPTCHA" class="captcha-image" data-i18n-alt="captcha_alt">
                <button type="button" class="refresh-captcha" data-i18n="refresh_captcha">🔄 Refresh</button>
            </p>
            <button type="submit" data-i18n="register">Sign up</button>
        </form>
        <p><a href="login.php" data-i18n="have_account">Already have an account? Sign in</a></p>
    </section>
    <script src="../assets/js/refresh-captcha.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/refresh-captcha.js'); ?>"></script>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>

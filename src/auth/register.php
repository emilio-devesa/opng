<?php
require("../includes/session.php");
session_start_secured();
require("../includes/csrf.php");
require("../includes/ratelimit.php");
require("../includes/password_validation.php");
require("../includes/http_input.php");
require("../includes/error_messages.php");
require("../includes/assets.php");
require_once("../includes/captcha.php");
require("../database.php");

if (!in_array($_SERVER["REQUEST_METHOD"], ["GET", "POST"], true)) {
    http_response_code(405);
    header("Allow: GET, POST");
    exit;
}

$form_error_key = '';
$posted_username = '';
$posted_email = '';
$asset_version = opng_asset_version();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Keep the submitted values so the form can be re-rendered with an inline
    // error instead of sending the user to a bare error page.
    $posted_username = http_string_parameter($_POST, 'username', 50) ?? '';
    $posted_email = http_string_parameter($_POST, 'email', 100) ?? '';
    $password = http_string_parameter($_POST, 'password', 72, false);
    $captcha_answer = http_string_parameter($_POST, 'captcha_answer', 5);

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $form_error_key = 'error_csrf';
    } elseif ($password === null || $captcha_answer === null) {
        $form_error_key = 'error_invalid_registration';
    } else {
        $password_error = password_validation_error($password);

        if (
            $posted_username === ''
            || preg_match('//u', $posted_username) !== 1
            || preg_match('/\p{C}/u', $posted_username) === 1
            || !filter_var($posted_email, FILTER_VALIDATE_EMAIL)
        ) {
            $form_error_key = 'error_invalid_registration';
        } elseif ($password_error !== null) {
            $form_error_key = 'password_policy';
        } else {
            $db = database_connect();
            if (!$db) {
                $form_error_key = 'error_database_unavailable';
            } else {
                $limit_result = rate_limit_hit($db, 'register', client_ip(), 5, 3600);
                if ($limit_result === RateLimitResult::Unavailable) {
                    $db->close();
                    $form_error_key = 'error_service_unavailable';
                } elseif ($limit_result === RateLimitResult::Limited) {
                    $db->close();
                    $form_error_key = 'error_registration_rate_limit';
                } elseif (!captcha_validate($captcha_answer)) {
                    $db->close();
                    $form_error_key = 'captcha_incorrect';
                } else {
                    $stmt = null;
                    try {
                        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                        $stmt->bind_param("s", $posted_email);
                        $stmt->execute();
                        $stmt->store_result();
                        if ($stmt->num_rows > 0) {
                            $stmt->close();
                            $stmt = null;
                            // Spend the same bcrypt work as the successful path
                            // so duplicated-email responses do not become a
                            // timing oracle. The visible message is generic.
                            password_hash($password, PASSWORD_DEFAULT);
                            $db->close();
                            $form_error_key = 'error_email_registered';
                        } else {
                            $stmt->close();
                            $stmt = null;

                            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'user')");
                            $stmt->bind_param("sss", $posted_username, $posted_email, $passwordHash);
                            if (!$stmt->execute()) {
                                error_log("OPNG register.php: " . $stmt->error);
                                $stmt->close();
                                $db->close();
                                $form_error_key = 'error_registration';
                            } else {
                                $stmt->close();
                                $db->close();
                                header("Location: login.php?msg=" . urlencode("Registration successful. Please sign in."));
                                exit;
                            }
                        }
                    } catch (mysqli_sql_exception $exception) {
                        error_log("OPNG register.php: " . $exception->getMessage());
                        if ($stmt instanceof mysqli_stmt) {
                            $stmt->close();
                        }
                        $db->close();
                        $form_error_key = 'error_auth_unavailable';
                    }
                }
            }
        }
    }

    // Every POST path either redirects on success or records an error key.
    http_response_code(opng_error_status($form_error_key));
}
    $lang_attr = 'en';
    $page_title = 'Sign up';
    $page_title_i18n = 'register_title';
    $base = '../';
    require(__DIR__ . '/../includes/layout_header.php');
?>
    <section class="auth-page">
        <h1 data-i18n="register_title">Sign up</h1>
        <?php if ($form_error_key !== ''): ?>
            <p class="form-error" role="alert" data-i18n="<?php echo htmlspecialchars($form_error_key, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars(opng_error_text($form_error_key), ENT_QUOTES, 'UTF-8'); ?>
            </p>
        <?php endif; ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <p>
                <label for="username" data-i18n="username">Username:</label>
                <input id="username" type="text" name="username" maxlength="50" autocomplete="username" value="<?php echo htmlspecialchars($posted_username, ENT_QUOTES, 'UTF-8'); ?>" required>
            </p>
            <p>
                <label for="email" data-i18n="email">Email:</label>
                <input id="email" type="email" name="email" maxlength="100" autocomplete="email" value="<?php echo htmlspecialchars($posted_email, ENT_QUOTES, 'UTF-8'); ?>" required>
            </p>
            <p>
                <label for="password" data-i18n="password">Password:</label>
                <input id="password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-policy" required>
                <span id="password-policy" class="field-help" data-i18n="password_policy">Use a password between 12 and 72 bytes.</span>
            </p>
            <?php captcha_render('captcha.php'); ?>
            <button type="submit" data-i18n="register">Sign up</button>
        </form>
        <p><a href="login.php" data-i18n="have_account">Already have an account? Sign in</a></p>
    </section>
    <script src="../assets/js/refresh-captcha.js?v=<?php echo $asset_version; ?>"></script>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>

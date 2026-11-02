<?php
require("../includes/session.php");
session_start_secured();
require("../includes/csrf.php");
require("../includes/ratelimit.php");
require("../includes/password_validation.php");
require("../includes/http_input.php");
require("../includes/error_messages.php");
require("../database.php");

if (!in_array($_SERVER["REQUEST_METHOD"], ["GET", "POST"], true)) {
    http_response_code(405);
    header("Allow: GET, POST");
    exit;
}

$user_id = http_positive_integer($_SESSION['user_id'] ?? null);
if ($user_id === null) {
    opng_error_redirect('error_permission', '../error.php');
}

$form_error_key = '';
$password_changed = false;
$csrf_token = csrf_token();

if ($_SERVER["REQUEST_METHOD"] === "GET" && !empty($_SESSION['change_password_success'])) {
    unset($_SESSION['change_password_success']);
    $password_changed = true;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $form_error_key = 'error_csrf';
    } else {
        $current_password = http_string_parameter($_POST, 'current_password', 72, false);
        $new_password = http_string_parameter($_POST, 'new_password', 72, false);
        $confirm_password = http_string_parameter($_POST, 'confirm_password', 72, false);

        if ($current_password === null || $new_password === null || $confirm_password === null) {
            $form_error_key = 'password_policy';
        } elseif (password_validation_error($new_password) !== null) {
            $form_error_key = 'password_policy';
        } elseif (!hash_equals($new_password, $confirm_password)) {
            $form_error_key = 'password_mismatch';
        } else {
            $db = database_connect();
            if (!$db) {
                $form_error_key = 'error_database_unavailable';
            } else {
                $limit_result = rate_limit_hit(
                    $db,
                    'change_password',
                    client_ip() . '|' . $user_id,
                    5,
                    600
                );

                if ($limit_result === RateLimitResult::Unavailable) {
                    $db->close();
                    $form_error_key = 'error_service_unavailable';
                } elseif ($limit_result === RateLimitResult::Limited) {
                    $db->close();
                    $form_error_key = 'error_password_rate_limit';
                } else {
                    $stmt = null;
                    try {
                        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $account = $result ? $result->fetch_assoc() : null;
                        if ($result instanceof mysqli_result) {
                            $result->free();
                        }
                        $stmt->close();
                        $stmt = null;

                        if (!is_array($account) || !is_string($account['password_hash'] ?? null)) {
                            $form_error_key = 'error_auth_unavailable';
                        } elseif (!password_verify($current_password, $account['password_hash'])) {
                            $form_error_key = 'password_incorrect';
                        } elseif (hash_equals($current_password, $new_password)) {
                            $form_error_key = 'password_unchanged';
                        } else {
                            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                            // Changing the password also invalidates any pending
                            // reset token for the account.
                            $stmt = $db->prepare(
                                "UPDATE users
                                 SET password_hash = ?, session_version = session_version + 1,
                                     reset_token = NULL, reset_token_expires = NULL
                                 WHERE id = ?"
                            );
                            $stmt->bind_param("si", $password_hash, $user_id);
                            $stmt->execute();
                            $updated = $stmt->affected_rows === 1;
                            $stmt->close();
                            $stmt = null;

                            if (!$updated) {
                                $form_error_key = 'error_database_operation';
                            } else {
                                // Keep this session valid while invalidating
                                // every other session of the account.
                                $_SESSION['session_version'] = (int) ($_SESSION['session_version'] ?? 0) + 1;
                                session_regenerate_id(true);
                                $_SESSION['change_password_success'] = true;
                                $db->close();
                                header('Location: change_password.php', true, 303);
                                exit;
                            }
                        }
                    } catch (mysqli_sql_exception $exception) {
                        error_log("OPNG change_password.php: " . $exception->getMessage());
                        if ($stmt instanceof mysqli_stmt) {
                            $stmt->close();
                        }
                        $form_error_key = 'error_service_unavailable';
                    }
                    $db->close();
                }
            }
        }
    }

    http_response_code(opng_error_status($form_error_key));
}

$lang_attr = 'en';
$page_title = 'Change password';
$page_title_i18n = 'change_password_title';
$base = '../';
require(__DIR__ . '/../includes/layout_header.php');
?>
    <section class="auth-page">
        <h1 data-i18n="change_password_title">Change password</h1>
        <?php if ($password_changed): ?>
            <p class="flash-message" role="status" data-i18n="password_changed">
                Password changed. Use your new password the next time you sign in.
            </p>
        <?php endif; ?>
        <?php if ($form_error_key !== ''): ?>
            <p class="form-error" role="alert" data-i18n="<?php echo htmlspecialchars($form_error_key, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars(opng_error_text($form_error_key), ENT_QUOTES, 'UTF-8'); ?>
            </p>
        <?php endif; ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <p>
                <label for="current_password" data-i18n="current_password">Current password:</label>
                <input id="current_password" type="password" name="current_password" maxlength="72" autocomplete="current-password" required>
            </p>
            <p>
                <label for="new_password" data-i18n="new_password">New Password:</label>
                <input id="new_password" type="password" name="new_password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-policy" required>
                <span id="password-policy" class="field-help" data-i18n="password_policy">Use a password between 12 and 72 bytes.</span>
            </p>
            <p>
                <label for="confirm_password" data-i18n="confirm_password">Confirm new password:</label>
                <input id="confirm_password" type="password" name="confirm_password" minlength="12" maxlength="72" autocomplete="new-password" required>
            </p>
            <button type="submit" data-i18n="change_password">Change Password</button>
        </form>
        <p><a href="../index.php" data-i18n="return">Return to Home</a></p>
    </section>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>

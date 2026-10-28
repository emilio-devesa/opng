<?php
require("../includes/session.php");
session_start_secured();
require("../includes/csrf.php");
require("../includes/password_validation.php");
require_once("../includes/password_reset.php");
require("../database.php");

$token = "";
$token_hash = null;
$error_message = "";
$success_message = "";
$show_form = false;
$simulation_enabled = password_reset_simulation_enabled();

if ($_SERVER["REQUEST_METHOD"] === "GET" && !empty($_SESSION['reset_password_success'])) {
    unset($_SESSION['reset_password_success']);
    $success_message = "Password updated. You can now sign in.";
} elseif (!$simulation_enabled) {
    $error_message = "Password resets are unavailable for this installation. Please contact the administrator.";
} elseif ($_SERVER["REQUEST_METHOD"] === "GET") {
    $candidate = $_GET["token"] ?? null;
    if (is_string($candidate)) {
        $token = $candidate;
        $token_hash = password_reset_token_hash($token);
    }

    if ($token_hash === null) {
        $error_message = "Invalid or expired reset link.";
    } else {
        $db = database_connect();
        if (!$db) {
            $error_message = "The password reset service is temporarily unavailable.";
        } else {
            try {
                $stmt = $db->prepare(
                    "SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()"
                );
                $stmt->bind_param("s", $token_hash);
                $stmt->execute();
                $result = $stmt->get_result();
                if (!$result) {
                    error_log("OPNG reset_password.php: could not read token lookup result");
                    $error_message = "The password reset service is temporarily unavailable.";
                } elseif ($result->fetch_assoc() !== null) {
                    $show_form = true;
                } else {
                    $error_message = "Invalid or expired reset link.";
                }
                $stmt->close();
            } catch (mysqli_sql_exception $exception) {
                error_log("OPNG reset_password.php: " . $exception->getMessage());
                $error_message = "The password reset service is temporarily unavailable.";
            }
            $db->close();
        }
    }
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error_message = "The request could not be validated. Please reload the page and try again.";
    } elseif (
        !isset($_POST["token"], $_POST["password"])
        || !is_string($_POST["token"])
        || !is_string($_POST["password"])
    ) {
        $error_message = "Invalid reset request.";
    } else {
        $token = $_POST["token"];
        $token_hash = password_reset_token_hash($token);
        $password = $_POST["password"];

        if ($token_hash === null) {
            $error_message = "Invalid or expired reset link.";
        } elseif (($password_error = password_validation_error($password)) !== null) {
            $error_message = $password_error;
            $show_form = true;
        } else {
            $reset_completed = false;
            $db = database_connect();
            if (!$db) {
                $error_message = "The password reset service is temporarily unavailable.";
            } else {
                try {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    // The conditional update consumes the token exactly once, including concurrent submissions.
                    $stmt = $db->prepare(
                        "UPDATE users
                         SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL
                         WHERE reset_token = ? AND reset_token_expires > NOW()"
                    );
                    $stmt->bind_param("ss", $password_hash, $token_hash);
                    $stmt->execute();
                    if ($stmt->affected_rows === 1) {
                        $reset_completed = true;
                    } else {
                        $error_message = "Invalid or expired reset link.";
                    }
                    $stmt->close();
                } catch (mysqli_sql_exception $exception) {
                    error_log("OPNG reset_password.php: " . $exception->getMessage());
                    $error_message = "The password reset service is temporarily unavailable.";
                }
                $db->close();
            }

            if ($reset_completed) {
                $_SESSION['reset_password_success'] = true;
                header("Location: reset_password.php", true, 303);
                exit;
            }

        }
    }
}
$error_message_key = match ($error_message) {
    "Password resets are unavailable for this installation. Please contact the administrator." => 'reset_unavailable',
    "Invalid or expired reset link." => 'invalid_reset_link',
    "The password reset service is temporarily unavailable." => 'reset_service_unavailable',
    "The request could not be validated. Please reload the page and try again." => 'request_invalid',
    "Invalid reset request." => 'invalid_reset_request',
    "Choose a password between 12 and 72 bytes long." => 'password_policy',
    default => '',
};
?>
<?php
$lang_attr = 'en';
$page_title = 'Reset Password';
$page_title_i18n = 'reset_title';
$base = '../';
$no_referrer = true;
require(__DIR__ . '/../includes/layout_header.php');
?>
    <section class="auth-page">
    <h2 data-i18n="reset_title">Reset Password</h2>

    <?php if ($simulation_enabled): ?>
        <p data-i18n="development_reset_notice">Development-only reset. This route works only on localhost while OPNG_DEV_RESET=1.</p>
    <?php endif; ?>

    <?php if ($error_message !== ""): ?>
        <p role="alert"<?php echo $error_message_key !== '' ? ' data-i18n="' . htmlspecialchars($error_message_key, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
            <?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
        </p>
    <?php endif; ?>

    <?php if ($success_message !== ""): ?>
        <p role="status" data-i18n="password_updated"><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?></p>
        <p><a href="login.php" data-i18n="login">Sign in</a></p>
    <?php elseif ($show_form): ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <label for="password" data-i18n="new_password">New Password:</label>
            <input id="password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-policy" required>
            <span id="password-policy" class="field-help" data-i18n="password_policy">Use a password between 12 and 72 bytes.</span>
            <button type="submit" data-i18n="change_password">Change Password</button>
        </form>
    <?php endif; ?>
    </section>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>

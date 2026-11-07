<?php
require("../includes/session.php");
session_start_secured();
require("../includes/csrf.php");
require("../includes/password_validation.php");
require_once("../includes/password_reset.php");
require("../includes/http_input.php");
require("../includes/ratelimit.php");
require_once("../includes/audit_log.php");
require("../database.php");

if (!in_array($_SERVER["REQUEST_METHOD"], ["GET", "POST"], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$token_hash = null;
$error_message = "";
$success_message = "";
$show_form = false;
$simulation_enabled = password_reset_simulation_enabled();

header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private, max-age=0');

if ($_SERVER["REQUEST_METHOD"] === "GET" && !empty($_SESSION['reset_password_success'])) {
    unset($_SESSION['reset_password_success']);
    password_reset_session_clear();
    $success_message = "Password updated. You can now sign in.";
} elseif (!$simulation_enabled) {
    $error_message = "Password resets are unavailable for this installation. Please contact the administrator.";
} elseif ($_SERVER["REQUEST_METHOD"] === "GET") {
    $candidate = http_string_parameter($_GET, 'token', 64, false);
    if ($candidate !== null) {
        $token_hash = password_reset_token_hash($candidate);
        if ($token_hash === null) {
            $error_message = "Invalid or expired reset link.";
        } else {
            $db = database_connect();
            if (!$db) {
                $error_message = "The password reset service is temporarily unavailable.";
            } else {
                $stmt = null;
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
                    } else {
                        $token_record = $result->fetch_assoc();
                        $result->free();
                        if ($token_record !== null) {
                            // Exchange the URL token for a short-lived session
                            // and redirect so the token is not kept in the
                            // address bar or browser history.
                            password_reset_session_store($token_hash);
                            $stmt->close();
                            $db->close();
                            header("Location: reset_password.php", true, 303);
                            exit;
                        }
                        $error_message = "Invalid or expired reset link.";
                    }
                    $stmt->close();
                    $stmt = null;
                } catch (mysqli_sql_exception $exception) {
                    error_log("OPNG reset_password.php: " . $exception->getMessage());
                    if ($stmt instanceof mysqli_stmt) {
                        $stmt->close();
                    }
                    $error_message = "The password reset service is temporarily unavailable.";
                }
                $db->close();
            }
        }
    } else {
        $reset_session = password_reset_session_get();
        if ($reset_session !== null) {
            $token_hash = $reset_session['token_hash'];
            $show_form = true;
        } else {
            $error_message = "Invalid or expired reset link.";
        }
    }
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error_message = "The request could not be validated. Please reload the page and try again.";
    } elseif (($password = http_string_parameter($_POST, 'password', 72, false)) === null) {
        $error_message = "Invalid reset request.";
    } else {
        // Prefer the session exchange; keep accepting a posted token only for
        // forms rendered before an upgrade.
        $reset_session = password_reset_session_get();
        $posted_token = http_string_parameter($_POST, 'token', 64, false);
        $token_hash = $reset_session['token_hash']
            ?? ($posted_token !== null ? password_reset_token_hash($posted_token) : null);
        if ($reset_session === null && $token_hash !== null) {
            password_reset_session_store($token_hash);
        }

        if ($token_hash === null) {
            $error_message = "Invalid reset request.";
        } elseif (($password_error = password_validation_error($password)) !== null) {
            $error_message = $password_error;
            $show_form = $reset_session !== null || $posted_token !== null;
        } else {
            $reset_completed = false;
            $db = database_connect();
            if (!$db) {
                $error_message = "The password reset service is temporarily unavailable.";
            } else {
                $stmt = null;
                try {
                    // Bound the endpoint before any cryptographic work. The
                    // session cookie can be dropped, so the persistent
                    // per-IP counter is the effective limit.
                    $limit_result = rate_limit_hit($db, 'reset_password', client_ip(), 5, 3600);
                    if ($limit_result === RateLimitResult::Unavailable) {
                        $error_message = "The password reset service is temporarily unavailable.";
                    } elseif ($limit_result === RateLimitResult::Limited) {
                        $error_message = "Too many password reset attempts. Please try again later.";
                    } else {
                        // Validate the token before calling password_hash():
                        // a forged token must never trigger an unauthenticated
                        // bcrypt DoS (SEC-01).
                        $stmt = $db->prepare(
                            "SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()"
                        );
                        $stmt->bind_param("s", $token_hash);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $token_record = $result ? $result->fetch_assoc() : null;
                        $reset_user_id = is_array($token_record) ? (int) ($token_record['id'] ?? 0) : 0;
                        if ($result instanceof mysqli_result) {
                            $result->free();
                        }
                        $stmt->close();
                        $stmt = null;

                        if ($token_record === null) {
                            $error_message = "Invalid or expired reset link.";
                        } else {
                            $password_hash = password_hash($password, PASSWORD_DEFAULT);
                            // The conditional update consumes the token exactly once, including concurrent submissions.
                            $stmt = $db->prepare(
                                "UPDATE users
                                 SET password_hash = ?, session_version = session_version + 1,
                                     reset_token = NULL, reset_token_expires = NULL
                                 WHERE reset_token = ? AND reset_token_expires > NOW()"
                            );
                            $stmt->bind_param("ss", $password_hash, $token_hash);
                            $stmt->execute();
                            if ($stmt->affected_rows === 1) {
                                $reset_completed = true;
                                audit_log_record($db, $reset_user_id, 'auth.reset_completed', '');
                            } else {
                                $error_message = "Invalid or expired reset link.";
                            }
                            $stmt->close();
                            $stmt = null;
                        }
                    }
                } catch (mysqli_sql_exception $exception) {
                    error_log("OPNG reset_password.php: " . $exception->getMessage());
                    if ($stmt instanceof mysqli_stmt) {
                        $stmt->close();
                    }
                    $error_message = "The password reset service is temporarily unavailable.";
                }
                $db->close();
            }

            if ($reset_completed) {
                password_reset_session_clear();
                csrf_rotate();
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
    "Too many password reset attempts. Please try again later." => 'reset_rate_limit',
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
    <h1 data-i18n="reset_title">Reset Password</h1>

    <?php if ($simulation_enabled): ?>
        <p data-i18n="development_reset_notice">Development-only reset. This route works only on localhost while OPNG_DEV_RESET=1.</p>
    <?php endif; ?>

    <?php if ($error_message !== ""): ?>
        <p role="alert" id="form-error"<?php echo $error_message_key !== '' ? ' data-i18n="' . htmlspecialchars($error_message_key, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
            <?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
        </p>
    <?php endif; ?>

    <?php if ($success_message !== ""): ?>
        <p role="status" data-i18n="password_updated"><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?></p>
        <p><a href="login.php" data-i18n="login">Sign in</a></p>
    <?php elseif ($show_form): ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <label for="password" data-i18n="new_password">New Password:</label>
            <input id="password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-policy<?php echo $error_message_key === 'password_policy' ? ' form-error' : ''; ?>"<?php echo $error_message_key === 'password_policy' ? ' aria-invalid="true" autofocus' : ''; ?> required>
            <span id="password-policy" class="field-help" data-i18n="password_policy">Use a password between 12 and 72 bytes.</span>
            <button type="submit" data-i18n="change_password">Change Password</button>
        </form>
    <?php endif; ?>
    </section>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>

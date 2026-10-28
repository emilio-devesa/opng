<?php
require("../includes/session.php");
session_start_secured();
require("../includes/csrf.php");
require("../includes/ratelimit.php");
require_once("../includes/password_reset.php");
require("../includes/http_input.php");
require("../database.php");

if (!in_array($_SERVER["REQUEST_METHOD"], ["GET", "POST"], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$message = "";
$reset_link = null;
$simulation_enabled = password_reset_simulation_enabled();

if (!$simulation_enabled) {
    $message = "Password resets are unavailable for this installation. Please contact the administrator.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $message = "The request could not be validated. Please reload the page and try again.";
    } elseif (
        ($email = http_string_parameter($_POST, 'email', 100)) === null
        || ($captcha_answer = http_string_parameter($_POST, 'captcha_answer', 5)) === null
    ) {
        $message = "Please enter a valid email address and CAPTCHA.";
    } else {
        $captcha_answer = strtoupper($captcha_answer);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Please enter a valid email address.";
        } elseif (
            !isset($_SESSION['captcha'])
            || !is_string($_SESSION['captcha'])
            || !hash_equals($_SESSION['captcha'], $captcha_answer)
        ) {
            $message = "Incorrect CAPTCHA. Please try again.";
            unset($_SESSION['captcha']);
        } else {
            unset($_SESSION['captcha']);
            $message = "If an account exists for this address, a reset link has been generated.";
            $db = database_connect();

            if (!$db) {
                $message = "The password reset service is temporarily unavailable.";
            } else {
                $ip = client_ip();
                $ip_allowed = rate_limit_hit($db, 'reset_ip', $ip, 5, 3600);
                $account_allowed = rate_limit_hit(
                    $db,
                    'reset_account',
                    strtolower($email),
                    5,
                    3600
                );

                if (!$ip_allowed || !$account_allowed) {
                    $message = "If an account exists for this address, a reset link has been generated.";
                } else {
                    $stmt = null;
                    $update = null;
                    try {
                        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                        $stmt->bind_param("s", $email);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $user = $result ? $result->fetch_assoc() : null;
                        if (!$result) {
                            error_log("OPNG request_reset.php: could not read account lookup result");
                            $message = "The password reset service is temporarily unavailable.";
                        } elseif ($user !== null) {
                            $token = bin2hex(random_bytes(32));
                            $token_hash = hash('sha256', $token);
                            $user_id = (int) $user['id'];
                            $update = $db->prepare(
                                "UPDATE users
                                 SET reset_token = ?, reset_token_expires = NOW() + INTERVAL 10 MINUTE
                                 WHERE id = ?"
                            );
                            $update->bind_param("si", $token_hash, $user_id);
                            if (!$update->execute()) {
                                error_log("OPNG request_reset.php: " . $update->error);
                                $message = "The password reset service is temporarily unavailable.";
                            } elseif ($update->affected_rows === 1) {
                                $reset_link = "reset_password.php?token=" . urlencode($token);
                            }
                            $update->close();
                            $update = null;
                        }
                        if ($result instanceof mysqli_result) {
                            $result->free();
                        }
                        $stmt->close();
                        $stmt = null;
                    } catch (mysqli_sql_exception $exception) {
                        error_log("OPNG request_reset.php: " . $exception->getMessage());
                        if ($update instanceof mysqli_stmt) {
                            $update->close();
                        }
                        if ($stmt instanceof mysqli_stmt) {
                            $stmt->close();
                        }
                        $message = "The password reset service is temporarily unavailable.";
                    }
                }

                $db->close();
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $_SESSION['reset_request_flash'] = [
        'message' => $message,
        'reset_link' => $reset_link,
    ];
    header("Location: request_reset.php", true, 303);
    exit;
}

if (isset($_SESSION['reset_request_flash'])) {
    $request_flash = $_SESSION['reset_request_flash'];
    unset($_SESSION['reset_request_flash']);
    if ($simulation_enabled && is_array($request_flash)) {
        if (isset($request_flash['message']) && is_string($request_flash['message'])) {
            $message = $request_flash['message'];
        }
        if (isset($request_flash['reset_link']) && is_string($request_flash['reset_link'])) {
            $reset_link = $request_flash['reset_link'];
        }
    }
}

$message_key = match ($message) {
    "Password resets are unavailable for this installation. Please contact the administrator." => 'reset_unavailable',
    "The request could not be validated. Please reload the page and try again." => 'request_invalid',
    "Please enter a valid email address and CAPTCHA." => 'reset_email_captcha_required',
    "Please enter a valid email address." => 'email_invalid',
    "Incorrect CAPTCHA. Please try again." => 'captcha_incorrect',
    "If an account exists for this address, a reset link has been generated." => 'reset_requested',
    "The password reset service is temporarily unavailable." => 'reset_service_unavailable',
    default => '',
};
?>
<?php
$lang_attr = 'en';
$page_title = 'Request Password Reset';
$page_title_i18n = 'request_reset_title';
$base = '../';
require(__DIR__ . '/../includes/layout_header.php');
?>
    <section class="auth-page">
    <h2 data-i18n="request_reset_title">Request Password Reset</h2>

    <?php if ($simulation_enabled): ?>
        <p data-i18n="development_reset_notice">Development-only email simulation. The reset link is shown only on localhost and only while OPNG_DEV_RESET=1.</p>
    <?php endif; ?>

    <?php if ($message !== ""): ?>
        <p role="status"<?php echo $message_key !== '' ? ' data-i18n="' . htmlspecialchars($message_key, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </p>
    <?php endif; ?>

    <?php if ($reset_link !== null): ?>
        <section aria-labelledby="simulated-email-title">
            <h3 id="simulated-email-title" data-i18n="simulated_email">Simulated email</h3>
            <p data-i18n="reset_link_details">A password reset was requested for this account. The link expires in 10 minutes and can be used once.</p>
            <p><a href="<?php echo htmlspecialchars($reset_link, ENT_QUOTES, 'UTF-8'); ?>" data-i18n="open_reset_link">Open local reset link</a></p>
        </section>
    <?php endif; ?>

    <?php if ($simulation_enabled): ?>
        <form method="post" id="reset-form" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <p>
                <label for="email" data-i18n="email">Email:</label>
                <input id="email" type="email" name="email" maxlength="100" required>
            </p>
            <p>
                <label for="captcha_answer" data-i18n="captcha_label">Please enter the captcha text:</label>
                <input id="captcha_answer" type="text" name="captcha_answer" maxlength="5" required>
                <img src="captcha.php" alt="CAPTCHA" class="captcha-image" data-i18n-alt="captcha_alt">
                <button type="button" class="refresh-captcha" data-i18n="refresh_captcha">🔄 Refresh</button>
            </p>
            <button type="submit" data-i18n="send_reset_link">Send Reset Link</button>
        </form>
        <script src="../assets/js/refresh-captcha.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/refresh-captcha.js'); ?>"></script>
    <?php endif; ?>
    <p><a href="login.php" data-i18n="return_to_sign_in">Return to sign in</a></p>
    </section>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>

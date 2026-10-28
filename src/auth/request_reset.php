<?php
require("../includes/session.php");
session_start_secured();
require("../includes/csrf.php");
require("../includes/ratelimit.php");
require_once("../includes/password_reset.php");
require("../database.php");

$message = "";
$reset_link = null;
$simulation_enabled = password_reset_simulation_enabled();

if (!$simulation_enabled) {
    $message = "Password resets are unavailable for this installation. Please contact the administrator.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $message = "The request could not be validated. Please reload the page and try again.";
    } elseif (
        !isset($_POST["email"], $_POST["captcha_answer"])
        || !is_string($_POST["email"])
        || !is_string($_POST["captcha_answer"])
    ) {
        $message = "Please enter a valid email address and CAPTCHA.";
    } else {
        $email = trim($_POST["email"]);
        $captcha_answer = strtoupper(trim($_POST["captcha_answer"]));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
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
                        }
                        $stmt->close();
                    } catch (mysqli_sql_exception $exception) {
                        error_log("OPNG request_reset.php: " . $exception->getMessage());
                        $message = "The password reset service is temporarily unavailable.";
                    }
                }

                $db->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="request_reset_title">Request Password Reset</title>
    <link rel="stylesheet" href="../main.css">
</head>
<body>
    <h2 data-i18n="request_reset_title">Request Password Reset</h2>

    <?php if ($simulation_enabled): ?>
        <p><strong>Development-only email simulation.</strong> The reset link is shown only on localhost and only while OPNG_DEV_RESET=1.</p>
    <?php endif; ?>

    <?php if ($message !== ""): ?>
        <p role="status"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if ($reset_link !== null): ?>
        <section aria-label="Simulated password reset email">
            <h3>Simulated email</h3>
            <p>A password reset was requested for this account. The link expires in 10 minutes and can be used once.</p>
            <p><a href="<?php echo htmlspecialchars($reset_link, ENT_QUOTES, 'UTF-8'); ?>">Open local reset link</a></p>
        </section>
    <?php endif; ?>

    <?php if ($simulation_enabled): ?>
        <form method="POST" id="reset-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <p>
                <label for="email" data-i18n="email">Email:</label>
                <input id="email" type="email" name="email" required>
            </p>
            <p>
                <label for="captcha_answer" data-i18n="captcha_label">Please enter the captcha text:</label>
                <input id="captcha_answer" type="text" name="captcha_answer" required>
                <img src="captcha.php" alt="CAPTCHA" class="captcha-image">
                <button type="button" class="refresh-captcha" data-i18n="refresh_captcha">🔄 Refresh</button>
            </p>
            <button type="submit" data-i18n="send_reset_link">Send Reset Link</button>
        </form>
        <script src="../assets/js/refresh-captcha.js"></script>
    <?php endif; ?>
</body>
</html>

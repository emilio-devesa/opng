<?php
// (v0.8) Procesa el POST creación/respuesta. Refactor: session/csrf/rules centralizadas.
require("includes/session.php");
session_start_secured();
require("includes/csrf.php");
require("includes/rules.php");
require("includes/db.php");
require("includes/ratelimit.php");
require("includes/paste_validation.php");
require("includes/user_quota.php");
require("includes/admin_metrics.php");
require("includes/http_input.php");
require("includes/error_messages.php");
require_once("includes/captcha.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    opng_error_redirect('error_csrf');
}

// Preserve leading/trailing whitespace exactly as pasted (indentation and the
// final newline are meaningful in source code). Empty input is still rejected
// by paste_validation_error(), which trims only for the emptiness check.
$text = http_string_parameter($_POST, 'input_text', OPNG_MAX_PASTE_BYTES + 1, false);
$topic = http_string_parameter($_POST, 'input_topic', OPNG_MAX_TOPIC_BYTES + 1);
$language = http_string_parameter($_POST, 'input_language', 255);
if ($text === null || $topic === null || $language === null) {
    opng_error_redirect('error_invalid_paste');
}

$validation_error = paste_validation_error($text, $topic);
if ($validation_error !== null || $language === '') {
    opng_error_redirect($validation_error ?? 'error_language_required');
}

try {
    $rules = load_rules();
} catch (RuntimeException $exception) {
    opng_error_redirect('error_language_config');
}
if (!isset($rules[$language])) {
    opng_error_redirect('error_invalid_language');
}

$user_id = $_SESSION['user_id'] ?? null;
if ($user_id !== null) {
    $user_id = http_positive_integer($user_id);
    if ($user_id === null) {
        opng_error_redirect('error_auth_unavailable');
    }
}

// Guests must solve the local CAPTCHA before creating an anonymous paste.
if ($user_id === null) {
    $captcha_answer = http_string_parameter($_POST, 'captcha_answer', 5);
    if (!captcha_validate($captcha_answer)) {
        opng_error_redirect('captcha_incorrect');
    }
}

// Conectar a la base de datos
$db = db_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable');
}

try {
    // Authenticated accounts get a larger budget than anonymous guests.
    $rate_limit_action = $user_id === null ? 'submit_guest' : 'submit_user';
    $rate_limit_max = $user_id === null ? 5 : 20;
    $limit_result = rate_limit_hit($db, $rate_limit_action, client_ip(), $rate_limit_max, 60);
    if ($limit_result === RateLimitResult::Unavailable) {
        $db->close();
        opng_error_redirect('error_service_unavailable');
    }
    if ($limit_result === RateLimitResult::Limited) {
        $db->close();
        opng_error_redirect('error_paste_rate_limit');
    }

    // Global soft cap for all paste text, configurable with
    // OPNG_STORAGE_QUOTA_BYTES (default 10 GiB). The counter row is locked
    // inside the same transaction as the insert, so concurrent writes cannot
    // overshoot the quota (SEC-04/ROB-03) and no full-table SUM() runs per
    // request (PERF-01).
    $id = bin2hex(random_bytes(8));
    $text_bytes = strlen($text);
    $transaction_started = false;
    $db->begin_transaction();
    $transaction_started = true;

    $global_used = global_quota_lock($db);
    if ($global_used === null) {
        error_log('OPNG submit.php: global storage counter is unavailable');
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_database_operation');
    }
    if (!global_quota_allows($global_used, $text_bytes)) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_global_storage_quota');
    }

    if ($user_id !== null) {
        $usage = lock_user_quota($db, (int) $user_id);
        if ($usage === null) {
            error_log('OPNG submit.php: authenticated account was not found while checking storage quota');
            $db->rollback();
            $transaction_started = false;
            $db->close();
            opng_error_redirect('error_database_operation');
        }
        if (!user_quota_allows($usage, 0, $text_bytes)) {
            $db->rollback();
            $transaction_started = false;
            $db->close();
            opng_error_redirect('error_storage_quota');
        }
    }

    if ($user_id === null) {
        $stmt = $db->prepare("INSERT INTO entries (id, user_id, language, text, topic) VALUES (?, NULL, ?, ?, ?)");
        $stmt->bind_param("ssss", $id, $language, $text, $topic);
    } else {
        $stmt = $db->prepare("INSERT INTO entries (id, user_id, language, text, topic) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sisss", $id, $user_id, $language, $text, $topic);
    }

    if (!$stmt->execute()) {
        error_log("OPNG submit.php: " . $stmt->error);
        $stmt->close();
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_save_paste');
    }
    $stmt->close();
    $stmt = null;

    if (!global_quota_consume($db, $text_bytes)) {
        error_log('OPNG submit.php: could not update the global storage counter');
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_save_paste');
    }

    if (!$db->commit()) {
        error_log("OPNG submit.php: failed to commit the paste transaction");
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_save_paste');
    }
    $transaction_started = false;
    // The dashboard aggregates changed; do not wait for the five-minute TTL.
    admin_metrics_invalidate($db);
    $db->close();
} catch (mysqli_sql_exception $exception) {
    error_log("OPNG submit.php: " . $exception->getMessage());
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if (isset($transaction_started) && $transaction_started) {
        $db->rollback();
    }
    $db->close();
    opng_error_redirect('error_save_paste');
}

header("Location: index.php?msg=" . urlencode("Paste created successfully"));
exit;
?>

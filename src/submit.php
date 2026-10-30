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
require("includes/http_input.php");
require("includes/error_messages.php");

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

// Conectar a la base de datos
$db = db_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable');
}

try {
    if (!rate_limit_hit($db, 'submit', client_ip(), 5, 60)) {
        $db->close();
        opng_error_redirect('error_paste_rate_limit');
    }

    $id = bin2hex(random_bytes(8));
    $user_id = $_SESSION['user_id'] ?? null;
    if ($user_id !== null) {
        $user_id = http_positive_integer($user_id);
        if ($user_id === null) {
            $db->close();
            opng_error_redirect('error_auth_unavailable');
        }
    }
    $transaction_started = false;
    if ($user_id !== null) {
        $db->begin_transaction();
        $transaction_started = true;
        $usage = lock_user_quota($db, (int) $user_id);
        if ($usage === null) {
            error_log('OPNG submit.php: authenticated account was not found while checking storage quota');
            $db->rollback();
            $db->close();
            opng_error_redirect('error_database_operation');
        }
        if (!user_quota_allows($usage, 0, strlen($text))) {
            $db->rollback();
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
        if ($transaction_started) {
            $db->rollback();
        }
        $db->close();
        opng_error_redirect('error_save_paste');
    }
    $stmt->close();
    $stmt = null;
    if ($transaction_started && !$db->commit()) {
        error_log("OPNG submit.php: failed to commit the paste transaction");
        $db->rollback();
        $db->close();
        opng_error_redirect('error_save_paste');
    }
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

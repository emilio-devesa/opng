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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    header("Location: error.php?msg=" . urlencode("Error: invalid CSRF token."));
    exit;
}

$text = http_string_parameter($_POST, 'input_text', OPNG_MAX_PASTE_BYTES + 1);
$topic = http_string_parameter($_POST, 'input_topic', OPNG_MAX_TOPIC_BYTES + 1);
$language = http_string_parameter($_POST, 'input_language', 255);
if ($text === null || $topic === null || $language === null) {
    header("Location: error.php?msg=" . urlencode("Invalid paste data."));
    exit;
}

$validation_error = paste_validation_error($text, $topic);
if ($validation_error !== null || $language === '') {
    $message = $validation_error ?? "The language is required.";
    header("Location: error.php?msg=" . urlencode($message));
    exit;
}

try {
    $rules = load_rules();
} catch (RuntimeException $exception) {
    header("Location: error.php?msg=" . urlencode("Language configuration is unavailable."));
    exit;
}
if (!isset($rules[$language])) {
    header("Location: error.php?msg=" . urlencode("Invalid language."));
    exit;
}

// Conectar a la base de datos
$db = db_connect();
if (!$db) {
    header("Location: error.php?msg=" . urlencode("Error: No se pudo conectar a la base de datos."));
    exit;
}

try {
    if (!rate_limit_hit($db, 'submit', client_ip(), 5, 60)) {
        $db->close();
        header("Location: error.php?msg=" . urlencode("Too many pastes created or the service is temporarily unavailable."));
        exit;
    }

    $id = bin2hex(random_bytes(8));
    $user_id = $_SESSION['user_id'] ?? null;
    if ($user_id !== null) {
        $user_id = http_positive_integer($user_id);
        if ($user_id === null) {
            $db->close();
            header("Location: error.php?msg=" . urlencode("Authentication service is temporarily unavailable."));
            exit;
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
            header("Location: error.php?msg=" . urlencode("Database operation failed."));
            exit;
        }
        if (!user_quota_allows($usage, 0, strlen($text))) {
            $db->rollback();
            $db->close();
            header("Location: error.php?msg=" . urlencode("Your account storage limit of 100 MiB has been reached."));
            exit;
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
        header("Location: error.php?msg=" . urlencode("Error saving the paste."));
        exit;
    }
    $stmt->close();
    $stmt = null;
    if ($transaction_started && !$db->commit()) {
        error_log("OPNG submit.php: failed to commit the paste transaction");
        $db->rollback();
        $db->close();
        header("Location: error.php?msg=" . urlencode("Error saving the paste."));
        exit;
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
    header("Location: error.php?msg=" . urlencode("Error saving the paste."));
    exit;
}

header("Location: index.php?msg=" . urlencode("Paste created successfully"));
exit;
?>

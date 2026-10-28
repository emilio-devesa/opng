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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: error.php?msg=" . urlencode("Request method not allowed."));
    exit;
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    header("Location: error.php?msg=" . urlencode("Error: invalid CSRF token."));
    exit;
}

if (
    !isset($_POST['input_text'], $_POST['input_language'], $_POST['input_topic'])
    || !is_string($_POST['input_text'])
    || !is_string($_POST['input_language'])
    || !is_string($_POST['input_topic'])
) {
    header("Location: error.php?msg=" . urlencode("Invalid paste data."));
    exit;
}

$text = trim($_POST['input_text']);
$topic = trim($_POST['input_topic']);
$language = trim($_POST['input_language']);

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
    header("Location: error.php?msg=" . urlencode("Error: The selected language ('" . $language . "') is not valid."));
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

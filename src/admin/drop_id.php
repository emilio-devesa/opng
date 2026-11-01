<?php
// (v0.8) Borrado de un paste. Refactor: session/csrf centralizados.
require_once('../includes/session.php');
session_start_secured();
require_once('../includes/csrf.php');
require_once('../database.php');
require_once('../includes/http_input.php');
require_once('../includes/account_role.php');
require_once("../includes/error_messages.php");
require_once('../includes/admin_metrics.php');
require_once('../includes/audit_log.php');

// Verificar si el usuario está autenticado
if (!isset($_SESSION['user_id'])) {
    opng_error_redirect('error_permission', '../error.php');
}

// Verificar que la solicitud es POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// Verificar token CSRF
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    opng_error_redirect('error_csrf', '../error.php');
}

// Validar el ID proporcionado
$input_ID = http_string_parameter($_POST, 'input_ID', 255, false);
if ($input_ID === null || !http_valid_entry_id($input_ID)) {
    opng_error_redirect('error_invalid_id', '../error.php');
}

$user_id = http_positive_integer($_SESSION['user_id'] ?? null);
if ($user_id === null) {
    opng_error_redirect('error_permission', '../error.php');
}
// Conectar a la base de datos
$db = database_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable', '../error.php');
}

$transaction_started = false;
try {
    $db->begin_transaction();
    $transaction_started = true;
    $user_role = refresh_account_role($db);
    if ($user_role === null) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_permission', '../error.php');
    }

    // Si el usuario es administrador, puede eliminar cualquier paste.
    if ($user_role === "admin") {
        $stmt = $db->prepare("DELETE FROM entries WHERE id = ?");
        $stmt->bind_param("s", $input_ID);
    } else {
        $stmt = $db->prepare("DELETE FROM entries WHERE id = ? AND user_id = ?");
        $stmt->bind_param("si", $input_ID, $user_id);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Entry deletion query failed.');
    }
    $deleted = $stmt->affected_rows === 1;
    $stmt->close();
    if ($deleted) {
        audit_log_record($db, $user_id, 'entry.delete', $input_ID);
    }
    if (!$db->commit()) {
        throw new RuntimeException('Entry deletion transaction could not be committed.');
    }
    $transaction_started = false;
    admin_metrics_invalidate($db);
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log("OPNG drop_id.php: " . $exception->getMessage());
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if ($transaction_started) {
        $db->rollback();
    }
    $db->close();
    opng_error_redirect('error_database_operation', '../error.php');
}

$db->close();
if ($deleted) {
    header("Location: ../index.php?msg=" . urlencode("Paste deleted successfully"));
    exit;
}

opng_error_redirect('error_permission', '../error.php');

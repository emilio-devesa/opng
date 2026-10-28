<?php
require("../includes/session.php");
session_start_secured();
require("../includes/csrf.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../index.php", true, 303);
    exit;
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    header("Location: ../error.php?msg=" . urlencode("Error: invalid CSRF token."));
    exit;
}

$_SESSION = [];
$cookie_parameters = session_get_cookie_params();
if (ini_get('session.use_cookies')) {
    $cookie_removed = setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie_parameters['path'],
        'domain' => $cookie_parameters['domain'],
        'secure' => $cookie_parameters['secure'],
        'httponly' => $cookie_parameters['httponly'],
        'samesite' => $cookie_parameters['samesite'],
    ]);
    if (!$cookie_removed) {
        error_log("OPNG logout.php: could not expire the session cookie");
    }
}

if (!session_destroy()) {
    error_log("OPNG logout.php: could not destroy the session");
    http_response_code(500);
    exit;
}

header("Location: ../index.php", true, 303);
exit;

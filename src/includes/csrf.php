<?php
// includes/csrf.php
// Gestión centralizada del token CSRF (evita la generación repetida en cada página).

function csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Campo oculto listo para incrustar en formularios POST
function csrf_input() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

// Verifica el token enviado por POST; no ataca el flujo, devuelve bool
function csrf_valid($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

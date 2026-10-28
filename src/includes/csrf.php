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
function csrf_valid($token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $known_token = $_SESSION['csrf_token'] ?? null;
    return is_string($known_token) && is_string($token) && hash_equals($known_token, $token);
}

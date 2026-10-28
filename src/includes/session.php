<?php
// includes/session.php
// Inicio de sesión con configuración endurecida de cookies (fix S10 parcial):
//   - httponly: la cookie no es accesible desde JavaScript (mitiga el robo de sesión vía XSS)
//   - samesite=Lax: reduce el riesgo de uso cross-site (se elige Lax en vez de Strict
//     para no romper la navegación desde enlaces externos en un pastebin público)
//   - secure: se activa automáticamente si la petición llega por HTTPS
//
// Uso: en lugar de session_start(), llamar a session_start_secured().

function session_start_secured() {
    if (session_status() !== PHP_SESSION_NONE) {
        return; // ya iniciada
    }

    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

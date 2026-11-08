<?php

declare(strict_types=1);

// includes/csrf.php
// Centralized CSRF token handling (avoids regenerating it on every page).

require_once(__DIR__ . '/session.php');

function csrf_token()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start_secured();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Hidden field ready to embed in POST forms
function csrf_input()
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/**
 * Replace the CSRF token after a privilege change (login, password change or
 * reset). Any page rendered before the change keeps using the old token, so
 * rotate on the redirect target rather than mid-form (SEC-13).
 */
function csrf_rotate(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start_secured();
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Validates the token sent by POST and returns a boolean.
function csrf_valid($token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start_secured();
    }

    $known_token = $_SESSION['csrf_token'] ?? null;
    return is_string($known_token) && is_string($token) && hash_equals($known_token, $token);
}

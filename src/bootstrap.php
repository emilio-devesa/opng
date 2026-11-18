<?php

declare(strict_types=1);

// bootstrap.php
// Shared entry-point bootstrap (HYG-08).
//
// Every web entry point used to repeat the same require list in a fragile
// order. This file registers the class autoloader and loads the foundation
// function libraries once; entry points only require their domain modules.
//
// Existing code keeps using plain functions. The autoloader is for future
// classes under the Opng\ namespace, mapped to src/classes/ (or to the
// directory in OPNG_CLASSES_DIR when a test overrides it).
//
//     require_once __DIR__ . '/bootstrap.php';      // src/*.php
//     require_once __DIR__ . '/../bootstrap.php';   // src/admin, src/auth

if (!defined('OPNG_CLASSES_DIR')) {
    define('OPNG_CLASSES_DIR', __DIR__ . '/classes');
}

/**
 * Resolve an Opng\ class name to a file below OPNG_CLASSES_DIR.
 */
function opng_class_path(string $class): ?string
{
    $prefix = 'Opng\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return null;
    }

    $relative = substr($class, strlen($prefix));
    if ($relative === '' || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $relative) !== 1) {
        return null;
    }

    $path = rtrim(OPNG_CLASSES_DIR, '/\\')
        . '/'
        . str_replace('\\', '/', $relative)
        . '.php';

    return is_file($path) ? $path : null;
}

spl_autoload_register(static function (string $class): void {
    $path = opng_class_path($class);
    if ($path !== null) {
        require_once $path;
    }
});

// Foundation libraries: constants, session helpers, input validation, shared
// messages, translations, logging, assets, CSRF, icons and the database
// helper. Domain modules (auth, captcha, backups, pagination, ...) stay in the
// entry points that need them.
require_once __DIR__ . '/includes/version.php';
require_once __DIR__ . '/includes/requirements.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/http_input.php';
require_once __DIR__ . '/includes/error_messages.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/logging.php';
require_once __DIR__ . '/includes/assets.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/hashing.php';
require_once __DIR__ . '/includes/db.php';

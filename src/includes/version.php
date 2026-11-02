<?php
// includes/version.php
// Single source of truth for the application version.
//
// Bump OPNG_VERSION when releasing and keep README.md and CHANGELOG.md in
// sync. The footer and any diagnostics read the value from here, so the
// version is no longer duplicated in templates.

if (!defined('OPNG_VERSION')) {
    define('OPNG_VERSION', '0.9.0');
}

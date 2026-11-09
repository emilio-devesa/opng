<?php

/**
 * Canonical error keys accepted by error.php and rendered by the shared
 * language catalogs. Keep this list in sync with src/assets/lang/*.json.
 */

declare(strict_types=1);

const OPNG_ERROR_KEYS = [
    'captcha_incorrect',
    'error_auth_unavailable',
    'error_backup_limit',
    'error_confirmation_required',
    'error_csrf',
    'error_database_operation',
    'error_database_unavailable',
    'error_email_registered',
    'error_empty_pastes',
    'error_empty_text',
    'error_entry_not_found',
    'error_global_storage_quota',
    'error_invalid_credentials',
    'error_invalid_cursor',
    'error_invalid_id',
    'error_invalid_language',
    'error_invalid_page',
    'error_invalid_paste',
    'error_invalid_registration',
    'error_invalid_search',
    'error_invalid_sort',
    'error_language_config',
    'error_language_required',
    'error_login_rate_limit',
    'error_method_not_allowed',
    'error_paste_rate_limit',
    'error_password_rate_limit',
    'error_permission',
    'error_registration',
    'error_registration_rate_limit',
    'error_reauth',
    'error_save_paste',
    'error_service_unavailable',
    'error_sign_in_to_edit',
    'error_storage_quota',
    'error_text_too_large',
    'error_thread_too_deep',
    'error_topic_required',
    'error_topic_too_large',
    'error_update_paste',
    'generic_error',
    'password_incorrect',
    'password_mismatch',
    'password_policy',
    'password_unchanged',
];

/**
 * Return a known error key, falling back to the generic message.
 */
function opng_error_key(string $candidate): string
{
    return in_array($candidate, OPNG_ERROR_KEYS, true) ? $candidate : 'generic_error';
}

/**
 * HTTP status associated with an error key.
 */
function opng_error_status(string $key): int
{
    return match ($key) {
        'error_permission' => 403,
        'error_reauth' => 403,
        'error_entry_not_found' => 404,
        'error_method_not_allowed' => 405,
        'error_invalid_credentials' => 401,
        'error_database_unavailable', 'error_auth_unavailable',
        'error_service_unavailable' => 503,
        'error_database_operation', 'error_save_paste', 'error_update_paste',
        'error_registration' => 500,
        'error_global_storage_quota', 'error_backup_limit' => 507,
        'error_login_rate_limit', 'error_registration_rate_limit',
        'error_paste_rate_limit', 'error_password_rate_limit' => 429,
        default => 400,
    };
}

/**
 * English fallback rendered in HTML before the language catalog loads.
 * Keeping it server-side means error pages still explain the problem when
 * JavaScript is unavailable.
 */
function opng_error_text(string $key): string
{
    static $catalog = null;
    if ($catalog === null) {
        $raw = @file_get_contents(__DIR__ . '/../assets/lang/en.json');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $catalog = is_array($decoded) ? $decoded : [];
    }

    $key = opng_error_key($key);
    $text = $catalog[$key] ?? null;

    return is_string($text) && $text !== '' ? $text : 'An error occurred.';
}

/**
 * Redirect to the error page with a validated, URL-safe key.
 */
function opng_error_redirect(string $key, string $error_path = 'error.php'): never
{
    header('Location: ' . $error_path . '?msg=' . rawurlencode(opng_error_key($key)));
    exit;
}

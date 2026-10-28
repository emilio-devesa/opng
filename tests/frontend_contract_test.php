<?php

$root = dirname(__DIR__);
$languages = ['en', 'es', 'de', 'fr', 'pt', 'zh'];
$catalogs = [];
$failures = [];

foreach ($languages as $language) {
    $path = $root . '/src/assets/lang/' . $language . '.json';
    $contents = file_get_contents($path);
    if ($contents === false) {
        $failures[] = "Missing translation catalog: {$language}.json";
        continue;
    }

    $catalog = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($catalog)) {
        $failures[] = "Translation catalog is not an object: {$language}.json";
        continue;
    }
    $catalogs[$language] = $catalog;
}

$reference_keys = array_keys($catalogs['en'] ?? []);
sort($reference_keys);
foreach ($catalogs as $language => $catalog) {
    $keys = array_keys($catalog);
    sort($keys);
    if ($keys !== $reference_keys) {
        $failures[] = "Translation keys differ in {$language}.json";
    }
    foreach ($catalog as $key => $value) {
        if (!is_string($value) || trim($value) === '') {
            $failures[] = "Translation {$language}.json:{$key} must be a non-empty string";
        }
    }
}

$required_keys = [
    'copy_error',
    'delete_confirm',
    'language_selector_label',
    'password_policy',
    'pagination',
    'reset_unavailable',
    'latest_entries',
    'newer_entries',
    'older_entries',
    'search_help',
    'storage_quota_notice',
    'error_invalid_cursor',
    'error_storage_quota',
    'home',
    'search_clear',
    'admin_search_entries',
    'admin_search_users',
    'role_user',
    'role_admin',
    'admin_preserve_pastes',
    'admin_delete_pastes',
    'admin_confirm_delete_user',
    'admin_last_admin_protected',
    'admin_self_change_denied',
    'admin_delete_database_reason',
    'admin_download_backup',
    'admin_backup_warning',
    'admin_user_confirm_action',
    'admin_metrics',
    'admin_metrics_total_pastes',
    'admin_metrics_total_users',
    'admin_metrics_by_language',
    'admin_metrics_by_user',
    'admin_metrics_paste_size',
    'admin_metrics_rest',
    'admin_metrics_unknown',
    'admin_metrics_no_data',
    'admin_metrics_failed',
];
foreach ($required_keys as $key) {
    if (!array_key_exists($key, $catalogs['en'] ?? [])) {
        $failures[] = "Required translation key is missing: {$key}";
    }
}

$source = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)
);
foreach ($source as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $contents = file_get_contents($file->getPathname());
    if ($contents === false) {
        $failures[] = "Could not read source file: {$file->getPathname()}";
        continue;
    }
    if (preg_match('/\s(?:on[a-z]+|style)\s*=/i', $contents) === 1) {
        $failures[] = "Inline event/style attribute found in {$file->getPathname()}";
    }
}

foreach ([$root . '/src/.htaccess', $root . '/deploy/nginx.example.conf'] as $policy_file) {
    $policy = file_get_contents($policy_file);
    if ($policy === false) {
        $failures[] = "Could not read CSP configuration: {$policy_file}";
        continue;
    }
    if (preg_match('/Content-Security-Policy[^\r\n]*unsafe-inline/i', $policy) === 1) {
        $failures[] = "CSP still permits unsafe-inline: {$policy_file}";
    }
    if (preg_match("/Content-Security-Policy[^\\r\\n]*style-src 'self'; script-src 'self'/", $policy) !== 1) {
        $failures[] = "CSP is missing same-origin style/script restrictions: {$policy_file}";
    }
}

$layout = file_get_contents($root . '/src/includes/layout_header.php');
if (!is_string($layout) || preg_match('/Content-Security-Policy:[^"]*style-src \'self\'; script-src \'self\'/', $layout) !== 1) {
    $failures[] = 'Shared application layout is missing the strict same-origin CSP';
}
if (!is_string($layout) || !str_contains($layout, 'data-language-version=')) {
    $failures[] = 'Language catalogs are missing versioned browser-cache URLs';
}
if (!is_string($layout) || !str_contains($layout, 'data-i18n-aria-label="home"')) {
    $failures[] = 'The global home control is missing its translated accessible label';
}
if (!is_string($layout) || !str_contains($layout, 'data-i18n-aria-label="search_clear"')) {
    $failures[] = 'The search clear control is missing its translated accessible label';
}

$language_script = file_get_contents($root . '/src/assets/js/language.js');
if (
    !is_string($language_script)
    || !str_contains($language_script, 'cache: "force-cache"')
    || !str_contains($language_script, 'translationCache')
) {
    $failures[] = 'Language catalog caching is missing';
}

$highlighter = $root . '/src/assets/vendor/highlight.min.js';
$license = $root . '/src/assets/vendor/HIGHLIGHT-LICENSE.txt';
if (!is_file($highlighter) || filesize($highlighter) < 1000) {
    $failures[] = 'Local Highlight.js bundle is missing or unexpectedly small';
}
$license_contents = is_file($license) ? file_get_contents($license) : false;
if (!is_string($license_contents) || !str_contains($license_contents, 'BSD 3-Clause License')) {
    $failures[] = 'Highlight.js BSD-3-Clause license is missing';
}

foreach ([
    '/src/assets/js/dark-mode.js',
    '/src/assets/js/language.js',
    '/src/assets/js/confirm.js',
    '/src/assets/js/copy.js',
    '/src/assets/js/search-clear.js',
    '/src/assets/js/admin-tools.js',
] as $script_path) {
    if (!is_file($root . $script_path)) {
        $failures[] = "Frontend resource is missing: {$script_path}";
    }
}

$backup_script = file_get_contents($root . '/src/admin/backup.php');
if (
    !is_string($backup_script)
    || !str_contains($backup_script, 'Content-Disposition: attachment')
    || !str_contains($backup_script, 'no-store')
    || !str_contains($backup_script, 'MYSQLI_TRANS_START_READ_ONLY')
) {
    $failures[] = 'Database backup is missing download headers, cache protection, or a read-only snapshot';
}

$admin_page = file_get_contents($root . '/src/admin/admin.php');
if (!is_string($admin_page)) {
    $failures[] = 'Administration page could not be read';
} else {
    $metrics_position = strpos($admin_page, 'data-i18n="admin_metrics"');
    $entries_position = strpos($admin_page, 'data-i18n="admin_manage_entries"');
    $users_position = strpos($admin_page, 'data-i18n="admin_manage_users"');
    $database_position = strpos($admin_page, 'data-i18n="admin_db_actions"');
    if (
        $metrics_position === false
        || $entries_position === false
        || $users_position === false
        || $database_position === false
        || !($metrics_position < $entries_position && $entries_position < $users_position && $users_position < $database_position)
    ) {
        $failures[] = 'Administration cards are not ordered metrics, entries, users, then database actions';
    }
    if (str_contains($admin_page, 'data-i18n="return"')) {
        $failures[] = 'Administration page still contains a redundant return-home link';
    }
    if (
        substr_count($admin_page, 'class="admin-field-clear"') !== 7
        || !str_contains($admin_page, 'data-i18n-aria-label="admin_clear_field"')
    ) {
        $failures[] = 'Administration inputs are missing localized clear controls';
    }
    if (
        !str_contains($admin_page, 'id="admin_record_number" name="admin_record_number"')
        || !str_contains($admin_page, 'autocomplete="new-password"')
        || !str_contains($admin_page, 'class="admin-role-options"')
        || substr_count($admin_page, 'name="role" value=') !== 2
        || substr_count($admin_page, 'class="admin-actions-divider"') !== 2
    ) {
        $failures[] = 'User role radios, non-login ID field, or search/action separators are missing';
    }
    $paste_choice_start = strpos($admin_page, 'class="admin-choice-group"');
    $paste_choice_end = $paste_choice_start === false
        ? false
        : strpos($admin_page, '</fieldset>', $paste_choice_start);
    $delete_user_button = strpos(
        $admin_page,
        'name="action" value="delete" class="danger" data-i18n="admin_delete_user"',
        $paste_choice_start === false ? 0 : $paste_choice_start
    );
    if (
        $paste_choice_start === false
        || $paste_choice_end === false
        || $delete_user_button === false
        || $delete_user_button > $paste_choice_end
        || !str_contains($admin_page, 'class="admin-choice-actions"')
        || !str_contains($admin_page, 'class="admin-entry-delete-form"')
        || !str_contains(
            file_get_contents($root . '/src/admin/user_action.php'),
            '$_POST[\'admin_record_number\']'
        )
    ) {
        $failures[] = 'Entry and user deletion controls are not positioned beside their related fields/options';
    }
}

$metrics_source = file_get_contents($root . '/src/admin/admin.php');
if (
    !is_string($metrics_source)
    || !str_contains($metrics_source, 'SUM(OCTET_LENGTH(text))')
    || !str_contains($metrics_source, 'LIMIT 5')
    || !str_contains($metrics_source, 'admin-pie-chart')
    || !str_contains($metrics_source, 'admin_metrics_rest')
    || str_contains($metrics_source, 'style=')
) {
    $failures[] = 'Admin metrics are missing paste storage aggregation, top-five grouping, accessible pie graphics, or CSP-safe markup';
}

$database_delete_page = file_get_contents($root . '/src/admin/delete_database.php');
if (
    !is_string($database_delete_page)
    || preg_match('/\bDROP\s+(?:DATABASE|SCHEMA)\b/i', $database_delete_page) === 1
    || !str_contains($database_delete_page, 'SELECT DATABASE() AS database_name')
    || str_contains($database_delete_page, "require_once('../config.php')")
) {
    $failures[] = 'Database deletion guidance must use the active DB connection and never execute a schema-drop statement';
}

$user_actions = file_get_contents($root . '/src/admin/user_action.php');
if (
    !is_string($user_actions)
    || !str_contains($user_actions, "'last_admin_protected'")
    || !str_contains($user_actions, "'self_change_denied'")
    || !str_contains($user_actions, 'delete_entries')
) {
    $failures[] = 'User management is missing its deletion safeguards or paste-retention choices';
}

$admin_tools = file_get_contents($root . '/src/assets/js/admin-tools.js');
if (
    !is_string($admin_tools)
    || !str_contains($admin_tools, '[data-select-entry]')
    || !str_contains($admin_tools, '[data-select-user]')
    || !str_contains($admin_tools, 'dataset.userRole')
) {
    $failures[] = 'Administration result selection does not populate entry and user IDs';
}

$admin_styles = file_get_contents($root . '/src/main.css');
if (
    !is_string($admin_styles)
    || str_contains($admin_styles, '.admin-search-form button {')
    || preg_match('/\.admin-field-clear\s*\{[^}]*margin:\s*0\s*!important;/s', $admin_styles) !== 1
) {
    $failures[] = 'Admin clear buttons are affected by search-submit spacing or lack a zero-margin alignment reset';
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo 'Frontend contracts passed: translation parity, global controls, catalog caching, no inline attributes, strict CSP, and local vendor license.' . PHP_EOL;

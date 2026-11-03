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
    'change_password_title',
    'current_password',
    'confirm_password',
    'password_changed',
    'password_incorrect',
    'password_mismatch',
    'password_unchanged',
    'skip_to_content',
    'error_password_rate_limit',
    'pagination',
    'reset_unavailable',
    'latest_entries',
    'newer_entries',
    'older_entries',
    'search_help',
    'storage_quota_notice',
    'error_invalid_cursor',
    'error_storage_quota',
    'error_invalid_sort',
    'error_empty_text',
    'error_text_too_large',
    'error_topic_required',
    'error_topic_too_large',
    'error_language_required',
    'length_mb_remaining',
    'length_meter_label',
    'line_numbers_hidden',
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
    'admin_seed_button',
    'admin_seed_title',
    'admin_seed_warning',
    'admin_seed_cli_only',
    'admin_seed_cleanup_warning',
    'admin_seed_password_warning',
    'error_service_unavailable',
    'error_global_storage_quota',
    'admin_storage_quota',
    'admin_storage_quota_warning',
    'admin_audit_log',
    'admin_audit_empty',
    'audit_entry_delete',
    'audit_backup_download',
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
    if (
        !str_contains($policy, "frame-ancestors 'none'")
        || !str_contains($policy, 'X-Frame-Options "DENY"')
    ) {
        $failures[] = "Frame embedding is not fully denied: {$policy_file}";
    }
}

$layout = file_get_contents($root . '/src/includes/layout_header.php');
if (!is_string($layout) || preg_match('/Content-Security-Policy:[^"]*style-src \'self\'; script-src \'self\'/', $layout) !== 1) {
    $failures[] = 'Shared application layout is missing the strict same-origin CSP';
}
if (
    !is_string($layout)
    || !str_contains($layout, "frame-ancestors 'none'")
    || !str_contains($layout, 'X-Frame-Options: DENY')
) {
    $failures[] = 'Shared application layout does not deny frame embedding';
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
if (
    !is_string($layout)
    || !str_contains($layout, 'class="skip-link" href="#Content"')
    || !str_contains($layout, 'data-i18n="skip_to_content"')
    || !str_contains($layout, '<main id="Content" tabindex="-1">')
) {
    $failures[] = 'The shared layout is missing the focusable skip link or main landmark';
}
if (
    !is_string($layout)
    || str_contains($layout, '<h1')
    || !str_contains($layout, '<p class="site-name" data-i18n="title_index">')
) {
    $failures[] = 'The shared layout must not add a second h1; the site name is a brand paragraph';
}
$heading_pages = [
    '/src/index.php',
    '/src/view.php',
    '/src/pastebin.php',
    '/src/error.php',
    '/src/auth/login.php',
    '/src/auth/register.php',
    '/src/auth/request_reset.php',
    '/src/auth/reset_password.php',
    '/src/auth/change_password.php',
    '/src/admin/admin.php',
    '/src/admin/empty.php',
    '/src/admin/delete_database.php',
    '/src/admin/seed_instructions.php',
];
foreach ($heading_pages as $heading_path) {
    $heading_source = file_get_contents($root . $heading_path);
    if (!is_string($heading_source) || substr_count($heading_source, '<h1') !== 1) {
        $failures[] = "Page must render exactly one h1 heading: {$heading_path}";
    }
}
if (
    !is_string($layout)
    || str_contains($layout, '<select id="language-selector" aria-label=')
    || !str_contains($layout, '<option value="en" lang="en">🇬🇧 English</option>')
) {
    $failures[] = 'The language selector still overrides its translated label or hides option names';
}
$theme_styles = file_get_contents($root . '/src/main.css');
if (
    !is_string($layout)
    || !str_contains($layout, 'class="language-select-wrap"')
    || !is_string($theme_styles)
    || !str_contains($theme_styles, '--control-bg:')
    || !str_contains($theme_styles, '--action-bg:')
    || !str_contains($theme_styles, '.language-select-wrap::after')
) {
    $failures[] = 'Header controls must use theme-aware surfaces and a centered custom language arrow';
}
if (
    !is_string($layout)
    || !str_contains($layout, 'aria-expanded="false"')
    || !str_contains($layout, 'aria-controls="header-search-help"')
    || !str_contains($layout, 'assets/js/search-help.js')
) {
    $failures[] = 'Search help is not an accessible disclosure with a click/touch toggle';
}
$search_help_script = file_get_contents($root . '/src/assets/js/search-help.js');
if (
    !is_string($search_help_script)
    || !str_contains($search_help_script, 'aria-expanded')
    || !str_contains($search_help_script, 'is-open')
    || !str_contains($search_help_script, 'Escape')
) {
    $failures[] = 'Search help script does not toggle aria-expanded or close on Escape';
}
foreach ([
    '/src/index.php' => 'data-i18n="latest_entries"',
    '/src/pastebin.php' => '<h1 data-i18n="create_new">',
    '/src/auth/login.php' => '<h1 data-i18n="login_title">',
    '/src/auth/register.php' => '<h1 data-i18n="register_title">',
    '/src/auth/request_reset.php' => '<h1 data-i18n="request_reset_title">',
    '/src/auth/reset_password.php' => '<h1 data-i18n="reset_title">',
    '/src/error.php' => '<h1 data-i18n="error_title">',
    '/src/admin/admin.php' => '<h1 data-i18n="admin_title">',
] as $heading_path => $heading_marker) {
    $heading_source = file_get_contents($root . $heading_path);
    if (!is_string($heading_source) || !str_contains($heading_source, $heading_marker)) {
        $failures[] = "Page content is missing its unique h1 heading: {$heading_path}";
    }
}
if (!is_string($view_page = file_get_contents($root . '/src/view.php')) || !str_contains($view_page, 'class="paste-title"')) {
    $failures[] = 'Paste view is missing its topic h1 heading';
}
$change_password_page = file_get_contents($root . '/src/auth/change_password.php');
if (
    !is_string($change_password_page)
    || !str_contains($change_password_page, 'csrf_valid')
    || !str_contains($change_password_page, 'rate_limit_hit')
    || !str_contains($change_password_page, 'password_validation_error')
    || !str_contains($change_password_page, 'session_regenerate_id(true)')
    || !str_contains($change_password_page, 'reset_token = NULL')
    || !str_contains($change_password_page, 'name="current_password"')
    || !str_contains($change_password_page, 'name="new_password"')
    || !str_contains($change_password_page, 'name="confirm_password"')
) {
    $failures[] = 'Change-password page is missing CSRF, rate limiting, policy, session regeneration, token invalidation, or the three password fields';
}
if (!is_string($layout) || !str_contains($layout, 'auth/change_password.php')) {
    $failures[] = 'The account navigation does not link to the change-password page';
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
    '/src/assets/js/search-help.js',
    '/src/assets/js/admin-tools.js',
    '/src/assets/js/length-meter.js',
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
    || !str_contains($backup_script, "REQUEST_METHOD'] !== 'POST'")
    || !str_contains($backup_script, 'csrf_valid')
    || !str_contains($backup_script, 'audit_log_record')
    || !str_contains($backup_script, 'OPNG_BACKUP_MAX_SECONDS')
    || !str_contains($backup_script, 'OPNG_BACKUP_MAX_BYTES')
    || !str_contains($backup_script, 'set_time_limit')
    || !str_contains($backup_script, 'error_backup_limit')
) {
    $failures[] = 'Database backup is missing download headers, cache protection, a read-only snapshot, POST/CSRF, audit logging, or time/size limits';
}

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
if (
    !is_array($composer)
    || !isset($composer['require']['ext-ctype'], $composer['require']['ext-dom'])
) {
    $failures[] = 'composer.json does not declare the ctype and DOM extensions';
}

$admin_guard = file_get_contents($root . '/src/includes/admin_guard.php');
if (
    !is_string($admin_guard)
    || !str_contains($admin_guard, 'function require_admin')
    || !str_contains($admin_guard, 'function require_authenticated_user')
) {
    $failures[] = 'The shared administrator guard is missing';
}

foreach ([
    '/src/admin/admin.php',
    '/src/admin/backup.php',
    '/src/admin/delete_database.php',
    '/src/admin/empty.php',
    '/src/admin/seed_instructions.php',
    '/src/admin/user_action.php',
] as $admin_path) {
    $admin_source = file_get_contents($root . $admin_path);
    if (
        !is_string($admin_source)
        || !str_contains($admin_source, 'admin_guard.php')
        || !str_contains($admin_source, 'require_admin(')
        || str_contains($admin_source, 'refresh_account_role')
    ) {
        $failures[] = "Administrator script does not use the shared guard: {$admin_path}";
    }
}

$owner_delete = file_get_contents($root . '/src/admin/drop_id.php');
if (
    !is_string($owner_delete)
    || !str_contains($owner_delete, 'admin_guard.php')
    || !str_contains($owner_delete, 'require_authenticated_user(')
) {
    $failures[] = 'Owner entry deletion does not use the shared authentication guard';
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
    $database_controls_start = strpos($admin_page, 'class="admin-db-controls"');
    $seed_button_position = strpos($admin_page, 'action="seed_instructions.php"');
    $backup_button_position = strpos($admin_page, 'action="backup.php"');
    $empty_button_position = strpos($admin_page, 'action="empty.php"');
    $delete_database_button_position = strpos($admin_page, 'action="delete_database.php"');
    if (
        $database_controls_start === false
        || $seed_button_position === false
        || $backup_button_position === false
        || $empty_button_position === false
        || $delete_database_button_position === false
        || $seed_button_position < $database_controls_start
        || $seed_button_position > $backup_button_position
        || $seed_button_position > $empty_button_position
        || $seed_button_position > $delete_database_button_position
    ) {
        $failures[] = 'Sample-data instructions are not the first database action';
    }
    if (preg_match('/<form action="backup\.php" method="post">/', $admin_page) !== 1) {
        $failures[] = 'The backup form must use POST';
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

$seed_instructions = file_get_contents($root . '/src/admin/seed_instructions.php');
$seed_script = file_get_contents($root . '/tools/seed.php');
if (
    !is_string($seed_instructions)
    || !str_contains($seed_instructions, 'php tools/seed.php')
    || !str_contains($seed_instructions, 'admin_seed_cleanup_warning')
    || !str_contains($seed_instructions, 'admin_seed_password_warning')
    || preg_match('/\b(?:require|include)(?:_once)?\s*\(?\s*[\'"][^\'"]*tools\/seed\.php/', $seed_instructions) === 1
    || !is_string($seed_script)
    || !str_contains($seed_script, "PHP_SAPI !== 'cli'")
) {
    $failures[] = 'Sample-data action must explain safe CLI use while keeping the seeder CLI-only';
}

$metrics_source = file_get_contents($root . '/src/admin/admin.php');
$metrics_include = file_get_contents($root . '/src/includes/admin_metrics.php');
if (
    !is_string($metrics_source)
    || !str_contains($metrics_source, 'admin-pie-chart')
    || !str_contains($metrics_source, 'admin_metrics_rest')
    || str_contains($metrics_source, 'style=')
    || !is_string($metrics_include)
    || !str_contains($metrics_include, 'SUM(OCTET_LENGTH(text))')
    || !str_contains($metrics_include, 'LIMIT 5')
    || !str_contains($metrics_include, 'admin_metrics_cache')
    || !str_contains($metrics_include, 'OPNG_ADMIN_METRICS_TTL')
) {
    $failures[] = 'Admin metrics are missing paste storage aggregation, top-five grouping, caching, accessible pie graphics, or CSP-safe markup';
}

$cache_invalidation_files = [
    '/src/admin/drop_id.php',
    '/src/admin/empty.php',
    '/src/admin/user_action.php',
];
foreach ($cache_invalidation_files as $invalidation_path) {
    $invalidation_source = file_get_contents($root . $invalidation_path);
    if (!is_string($invalidation_source) || !str_contains($invalidation_source, 'admin_metrics_invalidate($db)')) {
        $failures[] = "Admin action does not invalidate the metrics cache: {$invalidation_path}";
    }
}

if (
    !is_string($admin_page)
    || str_contains($admin_page, 'OFFSET ')
    || !str_contains($admin_page, 'admin_keyset_condition(')
    || !str_contains($admin_page, 'entry_direction')
    || !str_contains($admin_page, 'user_direction')
) {
    $failures[] = 'Admin listings must page with keyset cursors instead of OFFSET';
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

$pastebin_page = file_get_contents($root . '/src/pastebin.php');
$view_page = file_get_contents($root . '/src/view.php');
$length_script = file_get_contents($root . '/src/assets/js/length-meter.js');
if (
    !is_string($pastebin_page)
    || !str_contains($pastebin_page, 'data-length-meter-for="input_text"')
    || !is_string($view_page)
    || !str_contains($view_page, 'data-length-meter-for="edit_text"')
    || !str_contains($view_page, 'data-length-meter-for="reply_text"')
    || !is_string($length_script)
    || !str_contains($length_script, 'TextEncoder')
    || !str_contains($length_script, 'data-length-meter-for')
) {
    $failures[] = 'Paste length meter is missing from create/edit/reply forms or its script';
}

$register_page = file_get_contents($root . '/src/auth/register.php');
if (
    !is_string($register_page)
    || !str_contains($register_page, 'value="<?php echo htmlspecialchars($posted_username')
    || !str_contains($register_page, 'value="<?php echo htmlspecialchars($posted_email')
) {
    $failures[] = 'Registration form does not preserve username/email on validation errors';
}

$error_page = file_get_contents($root . '/src/error.php');
if (
    !is_string($error_page)
    || !str_contains($error_page, 'opng_error_text($message_key)')
    || str_contains($error_page, "'An error occurred.'")
) {
    $failures[] = 'Error page must render the server-side fallback from the catalog';
}

$asset_helper = file_get_contents($root . '/src/includes/assets.php');
$asset_consumers = [
    '/src/includes/layout_header.php',
    '/src/admin/admin.php',
    '/src/auth/register.php',
    '/src/auth/request_reset.php',
    '/src/view.php',
    '/src/pastebin.php',
];
if (
    !is_string($asset_helper)
    || !str_contains($asset_helper, 'function opng_asset_version()')
    || !str_contains($asset_helper, 'static $version = null;')
) {
    $failures[] = 'The shared asset-version helper is missing or not memoized';
}
foreach ($asset_consumers as $consumer_path) {
    $consumer = file_get_contents($root . $consumer_path);
    if (!is_string($consumer) || !str_contains($consumer, '<?php echo $asset_version; ?>')) {
        $failures[] = "Page does not reuse the shared asset version: {$consumer_path}";
    }
}
if (is_string($layout) && str_contains($layout, 'filemtime(')) {
    $failures[] = 'The shared layout still calls filemtime() per asset';
}

if (
    !is_string($view_page)
    || !str_contains($view_page, 'OPNG_MAX_RENDERED_LINE_NUMBERS')
    || !str_contains($view_page, 'line_numbers_hidden')
    || !str_contains($view_page, 'no-line-numbers')
    || str_contains($view_page, "assets/vendor/highlight.min.js?v=<?php echo filemtime")
) {
    $failures[] = 'View page must cap rendered line numbers and only load the highlighter when needed';
}
if (
    !is_string($admin_styles)
    || !str_contains($admin_styles, '.code-view.no-line-numbers')
    || !str_contains($admin_styles, '.line-numbers-notice')
) {
    $failures[] = 'Line-number cap styles are missing from the stylesheet';
}

$rate_limit_source = file_get_contents($root . '/src/includes/ratelimit.php');
if (
    !is_string($rate_limit_source)
    || !str_contains($rate_limit_source, 'ON DUPLICATE KEY UPDATE')
    || !str_contains($rate_limit_source, 'LAST_INSERT_ID(')
    || str_contains($rate_limit_source, 'INSERT IGNORE INTO rate_limits')
    || !str_contains($rate_limit_source, 'enum RateLimitResult')
    || !str_contains($rate_limit_source, 'RateLimitResult::Unavailable')
) {
    $failures[] = 'Rate limiting must record a hit with a single upsert and distinguish DB failures from limits';
}

$version_source = file_get_contents($root . '/src/includes/version.php');
$footer_layout = file_get_contents($root . '/src/includes/layout_footer.php');
if (
    !is_string($version_source)
    || !str_contains($version_source, "define('OPNG_VERSION'")
    || !is_string($footer_layout)
    || !str_contains($footer_layout, 'OPNG_VERSION')
    || str_contains($footer_layout, '>v0.8<')
) {
    $failures[] = 'The footer must render the centralized OPNG_VERSION constant';
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo 'Frontend contracts passed: translation parity, global controls, catalog caching, no inline attributes, strict CSP, and local vendor license.' . PHP_EOL;

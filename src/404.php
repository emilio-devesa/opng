<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 404.php
// Custom not-found document for Apache ErrorDocument and Nginx error_page
// (ROB-13). It renders the shared layout with the localized catalog and never
// touches the database, so it also works while the database is unavailable.
session_start_secured();
http_response_code(404);
header('Cache-Control: no-store, private, max-age=0');
header('Content-Type: text/html; charset=UTF-8');

// Error documents are served for the original URL, so relative asset paths
// would resolve against the wrong directory. Derive an absolute prefix from
// the script location instead.
$script_name = is_string($_SERVER['SCRIPT_NAME'] ?? null) ? $_SERVER['SCRIPT_NAME'] : '/404.php';
$base = rtrim(str_replace('\\', '/', dirname($script_name)), '/') . '/';
$page_title = 'Page not found';
$page_title_i18n = 'not_found_title';
require(__DIR__ . '/includes/layout_header.php');
?>
    <section class="error-page">
        <h1 data-i18n="not_found_title">Page not found</h1>
        <p role="alert" data-i18n="not_found_message">
            The page you requested does not exist or has moved.
        </p>
        <p><a href="<?php echo htmlspecialchars($base, ENT_QUOTES, 'UTF-8'); ?>index.php" data-i18n="return">Return to Home</a></p>
    </section>
<?php require(__DIR__ . '/includes/layout_footer.php');

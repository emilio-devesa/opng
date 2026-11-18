<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 500.php
// Custom server-error document for Apache ErrorDocument and Nginx error_page
// (ROB-13). It intentionally omits exception details: the structured log holds
// the request id and the diagnostic, while the visitor gets a safe message.
session_start_secured();
http_response_code(500);
header('Cache-Control: no-store, private, max-age=0');
header('Content-Type: text/html; charset=UTF-8');

$script_name = is_string($_SERVER['SCRIPT_NAME'] ?? null) ? $_SERVER['SCRIPT_NAME'] : '/500.php';
$base = rtrim(str_replace('\\', '/', dirname($script_name)), '/') . '/';
$page_title = 'Server error';
$page_title_i18n = 'server_error_title';
require(__DIR__ . '/includes/layout_header.php');
?>
    <section class="error-page">
        <h1 data-i18n="server_error_title">Server error</h1>
        <p role="alert" data-i18n="server_error_message">
            The server could not complete the request. Try again later.
        </p>
        <p><a href="<?php echo htmlspecialchars($base, ENT_QUOTES, 'UTF-8'); ?>index.php" data-i18n="return">Return to Home</a></p>
    </section>
<?php require(__DIR__ . '/includes/layout_footer.php');

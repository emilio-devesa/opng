<?php

require_once __DIR__ . '/bootstrap.php';
session_start_secured();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$requested_key = http_string_parameter($_GET, 'msg', 64, false) ?? '';
$message_key = opng_error_key($requested_key);
$message = opng_error_text($message_key);

http_response_code(opng_error_status($message_key));
$lang_attr = 'en';
$page_title = 'Error';
$page_title_i18n = 'error_title';
$base = '';
require(__DIR__ . '/includes/layout_header.php');
?>
    <section class="error-page">
        <h1 data-i18n="error_title">Error</h1>
        <p role="alert" data-i18n="<?php echo htmlspecialchars($message_key, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <p><a href="index.php" data-i18n="return">Return to Home</a></p>
    </section>
<?php require(__DIR__ . '/includes/layout_footer.php'); ?>

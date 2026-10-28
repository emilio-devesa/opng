<?php
require_once('../includes/session.php');
session_start_secured();
require_once('../includes/http_input.php');
require_once('../includes/account_role.php');
require_once('../database.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$session_user_id = http_positive_integer($_SESSION['user_id'] ?? null);
if ($session_user_id === null) {
    header("Location: ../error.php?msg=" . urlencode("Access denied. You don't have permission to view this page."));
    exit;
}

$db = database_connect();
if (!$db) {
    header("Location: ../error.php?msg=" . urlencode("Database connection error."));
    exit;
}

try {
    $current_role = refresh_account_role($db);
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log('OPNG seed_instructions.php: ' . $exception->getMessage());
    $db->close();
    header("Location: ../error.php?msg=" . urlencode("Database operation failed."));
    exit;
}
$db->close();
if ($current_role !== 'admin') {
    header("Location: ../error.php?msg=" . urlencode("Access denied. You don't have permission to view this page."));
    exit;
}

$lang_attr = 'en';
$page_title = 'Admin - Sample data';
$page_title_i18n = 'admin_seed_title';
$base = '../';
require(__DIR__ . '/../includes/layout_header.php');
?>
    <h2 data-i18n="admin_seed_title">Load sample data</h2>
    <section class="box admin-database-warning" aria-labelledby="sample-data-warning">
        <h3 id="sample-data-warning" data-i18n="admin_seed_warning">Sample data is loaded manually from the command line.</h3>
        <p data-i18n="admin_seed_cli_only">For security, the website does not execute the seeder. Run the command below from the repository root in a terminal.</p>
        <pre><code>php tools/seed.php</code></pre>
        <p data-i18n="admin_seed_cleanup_warning">The script first removes its previous fixtures: entries whose IDs start with seed and accounts using the @example.test email domain. Review those records before running it.</p>
        <p data-i18n="admin_seed_password_warning">All generated accounts use the known development password Password123!. Never use sample accounts or this password in production; remove the sample accounts after testing.</p>
        <a href="admin.php" data-i18n="cancel">Cancel</a>
    </section>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>

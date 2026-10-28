<?php
require_once('../includes/session.php');
session_start_secured();
require_once('../includes/http_input.php');
require_once('../includes/account_role.php');
require_once('../database.php');

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
    error_log('OPNG delete_database.php: ' . $exception->getMessage());
    $db->close();
    header("Location: ../error.php?msg=" . urlencode("Database operation failed."));
    exit;
}
if ($current_role !== 'admin') {
    $db->close();
    header("Location: ../error.php?msg=" . urlencode("Access denied. You don't have permission to view this page."));
    exit;
}

try {
    $database_result = $db->query('SELECT DATABASE() AS database_name');
    $database_row = $database_result->fetch_assoc();
    $database_result->free();
    $database_name = is_array($database_row) && is_string($database_row['database_name'] ?? null)
        ? $database_row['database_name']
        : '';
    if ($database_name === '') {
        throw new RuntimeException('Current database name is unavailable.');
    }
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log('OPNG delete_database.php: ' . $exception->getMessage());
    $db->close();
    header("Location: ../error.php?msg=" . urlencode("Database operation failed."));
    exit;
}
$db->close();

$lang_attr = 'en';
$page_title = 'Admin - Delete database';
$page_title_i18n = 'admin_delete_database';
$base = '../';
require(__DIR__ . '/../includes/layout_header.php');
?>
    <h2 data-i18n="admin_delete_database">Delete database completely</h2>
    <section class="box admin-database-warning" aria-labelledby="database-delete-warning">
        <h3 id="database-delete-warning" data-i18n="admin_delete_database_warning">This operation is manual and irreversible.</h3>
        <p data-i18n="admin_delete_database_reason">The web application deliberately has no permission to drop its database. Use MAMP's database administration tool instead; do not grant DROP privileges to the runtime account.</p>
        <ol>
            <li data-i18n="admin_delete_database_step_backup">Back up the database before deleting it.</li>
            <li>
                <span data-i18n="admin_delete_database_step_select">In MAMP's database administration tool, select the configured application database:</span>
                <code><?php echo htmlspecialchars($database_name, ENT_QUOTES, 'UTF-8'); ?></code>
            </li>
            <li data-i18n="admin_delete_database_step_drop">Use the database tool's drop/delete database action. This removes all pastes, user accounts, and application tables.</li>
        </ol>
        <p data-i18n="admin_delete_database_after">The site will stop working until the database and tables are recreated with the CLI installer.</p>
        <a href="admin.php" data-i18n="cancel">Cancel</a>
    </section>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>

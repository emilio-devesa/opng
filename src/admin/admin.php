<?php
// (v0.8) Panel de administración. Refactor: session/csrf centralizados.
require("../includes/session.php");
session_start_secured();

// Si el usuario no está autenticado o no es administrador, redirigir
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../error.php?msg=" . urlencode("Access denied. You don't have permission to view this page."));
    exit;
}

require("../includes/csrf.php");
$csrf_token = csrf_token();

$lang_attr = 'en';
$page_title = 'Administration - Open Pastebin NG';
$page_title_i18n = 'admin_title';
$base = '../';
require(__DIR__ . "/../includes/layout_header.php");
?>
    <h2 data-i18n="admin_title">Admin Panel</h2>

    <fieldset>
        <legend data-i18n="admin_db_actions">Database Actions</legend>
        <form action="empty.php" method="get">
            <button type="submit" data-i18n="empty_pastes" title="Empty">Empty Pastes</button>
        </form>
    </fieldset>

    <fieldset>
        <legend data-i18n="admin_manage_entries">Manage Entries</legend>
        <form method="post" action="drop_id.php" data-confirm-target="drop_confirm">
            <span id="drop_confirm" data-i18n="drop_confirm" hidden>Are you sure you want to drop this entry? This action cannot be undone.</span>
            <label for="input_ID" data-i18n="id_to_remove">ID to remove:</label>
            <input type="text" id="input_ID" name="input_ID" required>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" data-i18n="drop_entry" title="Drop">Drop Entry</button>
        </form>
    </fieldset>

    <p><a href="../index.php" data-i18n="return">Return to Home</a></p>

<?php require(__DIR__ . "/../includes/layout_footer.php"); ?>

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

$lang_attr = 'es';
$page_title = 'Administration - Open Pastebin NG';
$base = '../';
require(__DIR__ . "/../includes/layout_header.php");
?>
    <h2 data-i18n="admin_title">Admin Panel</h2>

    <fieldset>
        <legend data-i18n="admin_db_actions">Database Actions</legend>
        <form action="empty.php" method="get">
            <button type="button" data-i18n="empty_pastes" title="Empty" onclick="window.location.href='empty.php';">Empty Pastes</button>
        </form>
    </fieldset>

    <fieldset>
        <legend data-i18n="admin_manage_entries">Manage Entries</legend>
        <form method="post" action="drop_id.php" onsubmit="return confirm(document.getElementById('drop_confirm').textContent);">
            <span id="drop_confirm" data-i18n="drop_confirm" hidden>Are you sure you want to drop this entry? This action cannot be undone.</span>
            <label for="input_ID" data-i18n="id_to_remove">ID to remove:</label>
            <input type="text" id="input_ID" name="input_ID" required>
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <button type="submit" data-i18n="drop_entry" title="Drop">Drop Entry</button>
        </form>
    </fieldset>

    <p><a href="../index.php" data-i18n="return">Return to Home</a></p>

<?php require(__DIR__ . "/../includes/layout_footer.php"); ?>

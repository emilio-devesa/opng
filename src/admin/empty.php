<?php
// (v0.8) Vaciar pastes. Refactor: session/csrf centralizados.
require_once('../includes/session.php');
session_start_secured();
require_once('../includes/csrf.php');
require_once('../database.php');
require_once('../includes/http_input.php');

// Verificar que el usuario esté autenticado y sea administrador
// (fix S1/R1: require_once('login.php') no es un guarda de acceso válido)
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../error.php?msg=" . urlencode("Access denied. You don't have permission to view this page."));
    exit;
}

// Verificar que la solicitud es POST
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Sin POST: mostrar la página de confirmación
    $csrf_token = csrf_token();
    $lang_attr = 'en';
    $page_title = 'Admin - Empty database';
    $page_title_i18n = 'empty_title';
    $base = '../';
    require(__DIR__ . "/../includes/layout_header.php");
    ?>
            <h2 data-i18n="empty_title">⚠️ Empty database</h2>
            <p><strong data-i18n="empty_warning">This action will permanently delete all stored pastes.</strong></p>
            <p data-i18n="empty_confirm">Are you sure you want to continue?</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="danger" data-i18n="empty_yes">Yes, empty it</button>
            </form>
            <a href="admin.php" data-i18n="cancel">Cancel</a>
    <?php
    require(__DIR__ . "/../includes/layout_footer.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

// Verificar token CSRF
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    header("Location: ../error.php?msg=" . urlencode("Error: invalid CSRF token."));
    exit;
}

// DELETE works with the runtime DML account; TRUNCATE would require DROP privileges.
$db = database_connect();
if (!$db) {
    header("Location: ../error.php?msg=" . urlencode("Database connection error."));
    exit;
}

try {
    if (!$db->query("DELETE FROM entries")) {
        error_log("OPNG empty.php: failed to delete entries: " . $db->error);
        header("Location: ../error.php?msg=" . urlencode("Error while emptying the database"));
    } else {
        header("Location: ../index.php?msg=" . urlencode("All pastes have been deleted"));
    }
} catch (mysqli_sql_exception $exception) {
    error_log("OPNG empty.php: failed to delete entries: " . $exception->getMessage());
    header("Location: ../error.php?msg=" . urlencode("Error while emptying the database"));
}

$db->close();
exit;

<?php
// (v0.8) Vaciar pastes. Refactor: session/csrf centralizados.
require_once('../includes/session.php');
session_start_secured();
require_once('../includes/csrf.php');
require_once('../database.php');

// Verificar que el usuario esté autenticado y sea administrador
// (fix S1/R1: require_once('login.php') no es un guarda de acceso válido)
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../error.php?msg=" . urlencode("Access denied. You don't have permission to view this page."));
    exit;
}

// Verificar que la solicitud es POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Sin POST: mostrar la página de confirmación
    $csrf_token = csrf_token();
    $lang_attr = 'en';
    $page_title = 'Admin - Empty database';
    $base = '../';
    require(__DIR__ . "/../includes/layout_header.php");
    ?>
            <h2 data-i18n="empty_title">⚠️ Empty database</h2>
            <p><strong data-i18n="empty_warning">This action will permanently delete all stored pastes.</strong></p>
            <p data-i18n="empty_confirm">Are you sure you want to continue?</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <button type="submit" style="background-color: red; color: white;" data-i18n="empty_yes">Yes, empty it</button>
            </form>
            <br>
            <a href="admin.php" data-i18n="cancel">Cancel</a>
    <?php
    require(__DIR__ . "/../includes/layout_footer.php");
    exit;
}

// Verificar token CSRF
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    header("Location: ../error.php?msg=" . urlencode("Error: invalid CSRF token."));
    exit;
}

// Conectar a MySQL y vaciar las tablas de entradas
// (fix S2: se sustituye el antiguo DROP DATABASE por TRUNCATE — vacía los pastes
// conservando la estructura y las cuentas de usuario)
$db = database_connect();
if (!$db) {
    header("Location: ../error.php?msg=" . urlencode("Database connection error."));
    exit;
}

if ($db->query("TRUNCATE TABLE entries")) {
    header("Location: ../index.php?msg=" . urlencode("All pastes have been deleted"));
} else {
    // Error genérico al usuario; detalle técnico al log del servidor (S6)
    error_log("OPNG empty.php: failed to truncate 'entries': " . $db->error);
    header("Location: ../error.php?msg=" . urlencode("Error while emptying the database"));
}

$db->close();
exit;

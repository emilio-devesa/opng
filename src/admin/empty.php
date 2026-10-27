<?php
session_start();
require_once('../config.php');
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
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrf_token = $_SESSION['csrf_token'];
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Administración - Vaciar Base de Datos</title>
        <link rel="stylesheet" href="../main.css">
    </head>
    <body>
        <div id="Content">
            <h2>⚠️ Vaciar Base de Datos</h2>
            <p><strong>Esta acción eliminará permanentemente todos los pastes almacenados.</strong></p>
            <p>¿Estás seguro de que quieres continuar?</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <button type="submit" style="background-color: red; color: white;">Sí, vaciar</button>
            </form>
            <br>
            <a href="admin.php">Cancelar</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Verificar token CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    header("Location: ../error.php?msg=" . urlencode("Error: CSRF token inválido."));
    exit;
}

// Conectar a MySQL y vaciar las tablas de entradas
// (fix S2: se sustituye el antiguo DROP DATABASE por TRUNCATE — vacía los pastes
// conservando la estructura y las cuentas de usuario)
$db = database_connect();
if (!$db) {
    header("Location: ../error.php?msg=" . urlencode("Error de conexión a la base de datos"));
    exit;
}

if ($db->query("TRUNCATE TABLE entries")) {
    header("Location: ../index.php?msg=" . urlencode("Base de datos vaciada correctamente"));
} else {
    // Error genérico al usuario; detalle técnico al log del servidor (S6)
    error_log("OPNG empty.php: fallo al truncar 'entries': " . $db->error);
    header("Location: ../error.php?msg=" . urlencode("Error al vaciar la base de datos"));
}

$db->close();
exit;

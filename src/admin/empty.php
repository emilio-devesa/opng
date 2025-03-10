<?php
session_start();
require_once('login.php'); // Asegura autenticación antes de ejecutar acciones críticas
require_once('config.php');
require_once('database.php');

// Verificar token CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Error: CSRF token inválido.");
    }

    // Conectar a MySQL y eliminar la base de datos
    $conn = new mysqli($mysql_server, $mysql_username, $mysql_password);
    if ($conn->connect_error) {
        die("Error de conexión: " . $conn->connect_error);
    }

    $sql = "DROP DATABASE IF EXISTS `$mysql_dbname`";
    if ($conn->query($sql) === TRUE) {
        echo "<p style='color: green;'>Base de datos eliminada correctamente.</p>";
    } else {
        echo "<p style='color: red;'>Error al eliminar la base de datos: " . $conn->error . "</p>";
    }

    $conn->close();
    exit;
}

// Generar token CSRF
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
    <title>Administración - Eliminar Base de Datos</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <div id="Content">
        <h2>⚠️ Eliminar Base de Datos</h2>
        <p><strong>Esta acción eliminará permanentemente todas las entradas almacenadas.</strong></p>
        <p>¿Estás seguro de que quieres continuar?</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <button type="submit" style="background-color: red; color: white;">Sí, eliminar</button>
        </form>
        <br>
        <a href="admin.php">Cancelar</a>
    </div>
</body>
</html>

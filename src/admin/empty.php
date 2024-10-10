<?php
require_once('login.php'); // Verifica que el usuario esté autenticado y autorizado
session_start();

// Generar un token CSRF para proteger la solicitud
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Si se envió el formulario de confirmación para vaciar la base de datos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token.");
    }

    // Conectar a la base de datos usando MySQLi
    require_once('../config.php');
    $conn = new mysqli($mysql_server, $mysql_username, $mysql_password);

    if ($conn->connect_error) {
        die("Error de conexión: " . $conn->connect_error);
    }

    // Ejecutar la eliminación de la base de datos
    $query = "DROP DATABASE $mysql_dbname";
    if ($conn->query($query)) {
        echo "<p>Database <strong>$mysql_dbname</strong> dropped successfully.</p>";
    } else {
        echo "<p>Error dropping database: " . $conn->error . "</p>";
    }

    // Cerrar la conexión
    $conn->close();
}
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="content-type" content="text/html; charset=iso-8859-1">
    <title>Admin - Empty Database</title>
    <style type="text/css" media="all">@import "../main.css";</style>
</head>
<body>
    <div id="Content">
        <h1>Empty Database</h1>
        <p><strong>Warning:</strong> This action will delete the entire database <strong><?php echo $mysql_dbname; ?></strong> and all of its contents. This action is irreversible.</p>
        
        <form method="post" onsubmit="return confirm('Are you sure you want to delete the entire database? This action cannot be undone.');">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <button type="submit">Confirm and Delete Database</button>
        </form>

        <br />
        <p><a href="../index.php">Return to index</a></p>
    </div>
</body>
</html>

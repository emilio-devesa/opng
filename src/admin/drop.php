<?php
require_once('login.php'); // Asegura que el usuario esté autenticado
require_once('../config.php');

// Conectar a la base de datos usando MySQLi
$conn = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);

// Verificar la conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Generar un token CSRF para proteger el formulario
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="content-type" content="text/html; charset=iso-8859-1">
    <title>Pastebin - Remove Entry</title>
    <style type="text/css" media="all">@import "../main.css";</style>
</head>
<body>
    <div id="Content">
        <h1>Remove Entry</h1>
        <form method="post" action="drop_id.php" onsubmit="return confirm('Are you sure you want to remove this entry?');">
            <label for="input_ID">ID to remove:</label>
            <input type="text" id="input_ID" name="input_ID" required><br /><br />
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="submit" value="Submit">
        </form>
        <br /><br />
        <p>Return to the <a href="../index.php">index</a></p><br />
    </div>
</body>
</html>
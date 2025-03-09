<?php
require_once('login.php'); // Asegura que el usuario esté autenticado
session_start();

// Verificar si el usuario está autenticado
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// Generar un token CSRF para proteger formularios
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
    <title>Administración - Open Pastebin</title>
    <link rel="stylesheet" href="../main.css">
</head>
<body>
    <div id="Content">
        <h1>Admin Panel</h1>

        <!-- Formulario para vaciar la base de datos con token CSRF -->
        <form action="empty.php" method="post" onsubmit="return confirm('Are you sure you want to empty the database? This action cannot be undone.');">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <button type="submit" title="Empty">Empty Database</button>
        </form>
        
        <!-- Formulario para eliminar una entrada por ID con token CSRF -->
        <form action="drop.php" method="post">
            <label for="entry_id">Drop an entry by ID number:</label><br>
            <input type="text" id="entry_id" name="entry_id" required>
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <button type="submit" title="Drop">Drop Entry</button>
        </form>

    </div>
</body>
</html>

<?php
session_start();
require_once('../auth/login.php'); // Asegura que el usuario esté autenticado

// Verificar si el usuario está autenticado correctamente
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit;
}

// Generar un token CSRF si no existe
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
    <title>Administration - Open Pastebin</title>
    <link rel="stylesheet" href="../main.css">
    <script>
        function redirectToEmpty() {
            window.location.href = 'empty.php';
        }
        function confirmDropID() {
            return confirm("Are you sure you want to drop this entry? This action cannot be undone.");
        }
    </script>
</head>
<body>
    <div id="Content">
        <h1>Admin Panel</h1>

        <fieldset>
            <legend>Database Actions</legend>
            <form action="empty.php" method="get">
                <button type="button" title="Empty" onclick="return redirectToEmpty()">Empty Database</button>
            </form>
        </fieldset>

        <fieldset>
            <legend>Manage Entries</legend>
            <form method="post" action="drop_id.php" onsubmit="return confirmDropID();">
                <label for="input_ID">ID to remove:</label>
                <input type="text" id="input_ID" name="input_ID" required>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <button type="submit" title="Drop">Drop Entry</button>
            </form>
        </fieldset>

        <p><a href="../index.php">Return to Home</a></p>
    </div>
</body>
</html>

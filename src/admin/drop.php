<?php
session_start();
require_once('login.php'); // Asegura que el usuario esté autenticado

// Generar un token CSRF para proteger el formulario
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
    <title>Open Pastebin - Remove Entry</title>
    <link rel="stylesheet" href="../main.css">
    <script>
        function confirmDeletion() {
            return confirm("Confirm deletion?");
        }
    </script>
</head>
<body>
    <div id="Content">
        <h1>Remove Entry</h1>
        <form method="post" action="drop_id.php" onsubmit="return confirmDeletion();">
            <label for="input_ID">ID to remove:</label>
            <input type="text" id="input_ID" name="input_ID" required>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <br /><br />
            <input type="submit" value="Submit">
        </form>
        <p>Return to the <a href="../index.php">index</a></p>
    </div>
</body>
</html>
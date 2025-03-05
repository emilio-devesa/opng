<?php
$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8') : "Ocurrió un error.";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Error</title>
</head>
<body>
    <h2>Error</h2>
    <p><?php echo $msg; ?></p>
    <p><a href="index.php">Volver al inicio</a></p>
</body>
</html>

<?php
require("config.php");
require("database.php");

// Conectar a la base de datos
$db = database_connect();
if (!$db) {
    die("Error de conexión con la base de datos.");
}

// Consulta para obtener las entradas
$sql = "SELECT id, topic, date FROM entries ORDER BY date DESC";
$result = $db->query($sql);

if (!$result) {
    die("Error en la consulta: " . $db->error);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Open Pastebin</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <div id="Content">
        <h2>Lista de Entradas</h2>
        <table border="1" cellpadding="5">
            <tr>
                <th>ID</th>
                <th>Tema</th>
                <th>Fecha</th>
            </tr>
            <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['id']); ?></td>
                <td>
                    <a href="view.php?id=<?php echo urlencode($row['id']); ?>">
                        <?php echo htmlspecialchars($row['topic']); ?>
                    </a>
                </td>
                <td><?php echo htmlspecialchars($row['date']); ?></td>
            </tr>
            <?php endwhile; ?>
        </table>
        <br>
        <a href="pastebin.php">Crear Nueva Entrada</a>
        <br><br>
        <button id="theme-toggle">🌙 Modo Oscuro</button>
        <script src="assets/js/dark-mode.js"></script>
    </div>
</body>
</html>
<?php
// Cerrar conexión
$db->close();
?>

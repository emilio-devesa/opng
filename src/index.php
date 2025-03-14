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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="title_index">Open Pastebin NG</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <div id="Content">
        <h1 data-i18n="welcome">Welcome to Open Pastebin NG!</h1>
        <h2 data-i18n="list_all">List of Entries</h2>
        <table border="1" cellpadding="5">
            <tr>
                <th data-i18n="id">ID</th>
                <th data-i18n="topic">Topic</th>
                <th data-i18n="date">Date</th>
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
        <a href="pastebin.php" data-i18n="create_new">Create New Entry</a>
        <br><br>
        <button id="theme-toggle" data-i18n="dark_mode">Dark Mode</button>
        <script src="assets/js/dark-mode.js"></script>
        <br><br>
        <select id="language-selector">
            <option value="en">🇬🇧 English</option>
            <option value="es">🇪🇸 Español</option>
            <option value="de">🇩🇪 Deutsch</option>
            <option value="fr">🇫🇷 Français</option>
            <option value="pt">🇵🇹 Português</option>
            <option value="zh">🇨🇳 中文</option>
        </select>
        <script src="assets/js/language.js"></script>
    </div>
</body>
</html>
<?php
// Cerrar conexión
$db->close();
?>

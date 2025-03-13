<?php
require("config.php");
require("database.php");

// Verificar y obtener el ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: error.php?msg=" . urlencode("ID no válido"));
    exit;
}

$id = trim($_GET['id']);

// Conectar a la base de datos
$db = database_connect();
if (!$db) {
    header("Location: error.php?msg=" . urlencode("Error de conexión a la base de datos"));
    exit;
}

// Obtener la entrada desde la base de datos
$stmt = $db->prepare("SELECT id, language, text, topic, date FROM entries WHERE id = ?");
$stmt->bind_param("s", $id);
$stmt->execute();
$result = $stmt->get_result();
$array = $result->fetch_assoc();
$stmt->close();
$db->close();

if (!$array) {
    header("Location: error.php?msg=" . urlencode("Registro no encontrado"));
    exit;
}

// Cargar reglas desde rules.xml
$dom = new DOMDocument();
if (!$dom->load(__DIR__ . "/rules.xml")) {
    die("<p style='color: red;'>Error: No se pudo cargar rules.xml.</p>");
}

// Convertir XML a array
$rules = [];
foreach ($dom->getElementsByTagName('RULE') as $rule) {
    $name = trim($rule->getAttribute('name'));
    $rules[$name] = $name; // Solo almacenar el nombre del lenguaje
}

// Obtener y normalizar el lenguaje
$language = trim($array['language']);

// Contar lineas para el resaltado
$lines = explode("\n", $array['text']);



?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ver Entrada - Open Pastebin</title>
    <link rel="stylesheet" href="main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/default.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script>hljs.highlightAll();</script>
</head>
<body>
    <div id="Content">
        <h2><?php echo htmlspecialchars($array['topic']); ?></h2>
        <p><strong>Lenguaje:</strong> <?php echo htmlspecialchars($language); ?></p>
        <p><strong>Fecha:</strong> <?php echo htmlspecialchars($array['date']); ?></p>
        <p><strong>ID:</strong> <?php echo htmlspecialchars($array['id']); ?></p>
        
        <h3>Contenido:</h3>
        <table border="1">
            <tr>
                <td align="right">
                    <pre><?php echo implode("\n", range(1, count($lines))); ?></pre>
                </td>
                <td nowrap align="left">
                    <pre><code class="language-<?php echo htmlspecialchars($language); ?>"><?php echo $array['text']; ?>
                    </code></pre>
                </td>
            </tr>
        </table>

        <h3>Editar Entrada</h3>
        <form method="post" action="submit.php">
            <label>Tema:</label>
            <input type="text" name="input_topic" value="RE: <?php echo htmlspecialchars($array['topic']); ?>"><br>

            <label for="input_language">Selecciona un lenguaje:</label><br>
            <select id="input_language" name="input_language" required>
                <?php foreach ($rules as $rule_name => $rule): ?>
                    <option value="<?php echo htmlspecialchars($rule_name); ?>" <?php echo ($rule_name === $language) ? "selected" : ""; ?>>
                        <?php echo htmlspecialchars($rule_name); ?>
                    </option>
                <?php endforeach; ?>
            </select><br>

            <label>Texto:</label><br>
            <textarea name="input_text" rows="25" cols="80"><?php echo $array['text']; ?>
            </textarea>
            <br><br>
            <input type="submit" value="Guardar Cambios">
        </form>
        
        <p><a href="index.php">Volver al inicio</a></p>
        <br><br>
        <button id="theme-toggle">🌙 Modo Oscuro</button>
        <script src="assets/js/dark-mode.js"></script>
    </div>
</body>
</html>

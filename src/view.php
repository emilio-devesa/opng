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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($array['topic']); ?></title>
    <link rel="stylesheet" href="main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/default.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script>hljs.highlightAll();</script>
</head>
<body>
    <div id="Content">
        <h2><?php echo htmlspecialchars($array['topic']); ?></h2>
        <div class="container">
            <div class="box">
                <h3 data-i18n="code:">Code:</h3>
                <label data-i18n="language:">Language:</label> <?php echo htmlspecialchars($language); ?><br>
                <label data-i18n="date">Date:</label> <?php echo htmlspecialchars($array['date']); ?><br>
                <label data-i18n="id">ID:</label> <?php echo htmlspecialchars($array['id']); ?><br>
                <table>
                    <tr>
                        <td nowrap align="right">
                            <pre><?php echo implode("\n", range(1, count($lines))); ?></pre>
                        </td>
                        <td nowrap align="left">
                            <div style="position: relative;">
                                <pre><code id="code-block" class="language-<?php echo htmlspecialchars($language); ?>"><?php echo $array['text']; ?></code></pre>
                                <button id="copy-button" data-i18n="copy" class="copy-button" onclick="copyToClipboard()">📋 Copy</button>
                            </div>
                        </td>
                    </tr>
                </table>
                <br>
            </div>
            <div class="box">
                <h3 data-i18n="edit:">Edit:</h3>
                <form method="post" action="submit.php">
                    <!-- Protección CSRF -->
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <label data-i18n="topic:">Topic:</label>
                    <input type="text" name="input_topic" value="RE: <?php echo htmlspecialchars($array['topic']); ?>"><br>
                    
                    <label data-i18n="select_language:" for="input_language">Select language:</label>
                    <select id="input_language" name="input_language" required>
                        <?php foreach ($rules as $rule_name => $rule): ?>
                            <option value="<?php echo htmlspecialchars($rule_name); ?>" <?php echo ($rule_name === $language) ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($rule_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select><br>
                    <br><br>
                    <textarea name="input_text" rows="<?php echo (max(count($lines), 10)+1); ?>" cols="80"><?php echo $array['text']; ?></textarea>
                    <br><br>
                    <button data-i18n="submit" id="submit" type="submit">Submit</button>
                </form>
            </div>
        </div>
        <br>
        <a href="index.php" data-i18n="return">Return to Home</a>
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
        <script src="assets/js/copy.js"></script>
    </div>
</body>
</html>

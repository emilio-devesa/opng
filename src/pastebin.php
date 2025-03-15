<?php
session_start();
require("config.php");
require("database.php");

// Generar un token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Conectar a la base de datos
$db = database_connect();

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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="title_pastebin">Open Pastebin NG - Create New Entry</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <div id="Content">
        <h2 data-i18n="create_new">Create New Entry</h2>
        <form method="post" action="submit.php">
            <!-- Protección CSRF -->
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <label data-i18n="topic:" for="input_topic">Topic:</label>
            <input type="text" id="input_topic" name="input_topic" required><br>

            <label data-i18n="select_language:" for="input_language">Select Language:</label>
            <select id="input_language" name="input_language" required>
                <?php
                foreach ($rules as $rule_name) {
                    echo "<option value=\"$rule_name\">" . htmlspecialchars($rule_name) . "</option>";
                }
                ?>
            </select><br>

            <label data-i18n="text" for="input_text">Enter your text here</label><br>
            <textarea id="input_text" name="input_text" rows="25" cols="80" required></textarea>
            <br><br>
            <button data-i18n="submit" id="submit" type="submit">Submit</button>
        </form>
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
    </div>
</body>
</html>

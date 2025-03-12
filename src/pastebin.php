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
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Open Pastebin - Crear Nueva Entrada</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <div id="Content">
        <h2>Crear Nueva Entrada</h2>
        <form method="post" action="submit.php">
            <!-- Protección CSRF -->
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <label for="input_topic">Tema:</label>
            <input type="text" id="input_topic" name="input_topic" required><br>

            <label for="input_language">Selecciona un lenguaje:</label><br>
            <select id="input_language" name="input_language" required>
                <?php
                foreach ($rules as $rule_name) {
                    echo "<option value=\"$rule_name\">" . htmlspecialchars($rule_name) . "</option>";
                }
                ?>
            </select><br>

            <label for="input_text">Introduce tu código:</label><br>
            <textarea id="input_text" name="input_text" rows="25" cols="80" required></textarea>
            <br><br>
            <input type="submit" value="Enviar">
        </form>
        <br>
        <p><a href="index.php">Volver al inicio</a></p>
    </div>
</body>
</html>

<?php
require("config.php");
require("xmlparser.php");

// Cargar reglas desde rules.xml
$xml_parser = new CXmlParser();
$rules = $xml_parser->parse("rules.xml");

// Verificar si hay reglas disponibles
if (!isset($rules['RULE']) || empty($rules['RULE'])) {
    die("Error: No hay reglas de lenguaje disponibles.");
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
        <h2>Crear Nueva Entrada</h2>
        <form method="post" action="submit.php">
            <label for="input_topic">Tema:</label>
            <input type="text" id="input_topic" name="input_topic" required><br>

            <label for="input_language">Selecciona un lenguaje:</label><br>
            <select id="input_language" name="input_language" required>
                <?php
                foreach ($rules['RULE'] as $rule) {
                    $rule_name = htmlspecialchars($rule['attributes']['NAME']);
                    echo "<option value=\"$rule_name\">$rule_name</option>"; // Usamos el nombre del lenguaje
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

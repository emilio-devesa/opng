<?php
require("config.php");
require("database.php");

// Inicializar el array de errores
$errors = [];

// Validar los datos enviados en el formulario
if (empty($_POST['input_text'])) {
    $errors[] = "El texto no puede estar vacío.";
}
if (empty($_POST['input_language'])) {
    $errors[] = "El lenguaje es obligatorio.";
}
if (empty($_POST['input_topic'])) {
    $errors[] = "El tema es obligatorio.";
}

// Si hay errores, los mostramos y detenemos el script
if (!empty($errors)) {
    echo "<p style='color: red;'><strong>Error:</strong></p><ul>";
    foreach ($errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul><p><a href='pastebin.php'>Volver</a></p>";
    exit;
}

// Obtener valores y limpiar entradas
$text = htmlspecialchars(trim($_POST['input_text']), ENT_QUOTES, 'UTF-8');
$topic = htmlspecialchars(trim($_POST['input_topic']), ENT_QUOTES, 'UTF-8');
$language = htmlspecialchars(trim($_POST['input_language']), ENT_QUOTES, 'UTF-8');

// Cargar rules.xml usando DOMDocument
$dom = new DOMDocument();
if (!$dom->load(__DIR__ . "/rules.xml")) {
    die("<p style='color: red;'>Error: No se pudo cargar rules.xml.</p>");
}

// Convertir XML a array (solo nombres de lenguajes)
$rules = [];
foreach ($dom->getElementsByTagName('RULE') as $rule) {
    $rules[trim($rule->getAttribute('name'))] = true;
}

// Verificar si el lenguaje existe en `rules.xml`
if (!isset($rules[$language])) {
    echo "<p style='color: red;'>Error: El lenguaje seleccionado ('" . htmlspecialchars($language) . "') no es válido.</p>";
    echo "<p><a href='pastebin.php'>Volver</a></p>";
    exit;
}

// Conectar a la base de datos
$db = database_connect();
if (!$db) {
    die("<p style='color: red;'>Error: No se pudo conectar a la base de datos.</p>");
}

// Generar un ID único
$id = bin2hex(random_bytes(8));

// Consulta preparada para insertar la entrada
$stmt = $db->prepare("INSERT INTO entries (id, language, text, topic) VALUES (?, ?, ?, ?)");
if (!$stmt) {
    die("<p style='color: red;'>Error en la preparación de la consulta: " . $db->error . "</p>");
}

$stmt->bind_param("ssss", $id, $language, $text, $topic);
$stmt->execute();
$stmt->close();
$db->close();

/* 
// Redireccionar automáticamente a la nueva entrada
header("Location: view.php?id=" . urlencode($id));
 */

// Redireccionar a la página principal
header("Location: index.php");
exit;
?>

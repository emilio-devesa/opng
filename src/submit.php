<?php
session_start(); // Iniciar sesión antes de usar $_SESSION
require("config.php");
require("database.php");

// Inicializar el array de errores
$errors = [];

// Verificar y obtener el ID
/* if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: error.php?msg=" . urlencode("ID no válido"));
    exit;
} */

// Obtener ID del usuario autenticado
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

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
$stmt = $db->prepare("INSERT INTO entries (id, user_id, language, text, topic) VALUES (?, ?, ?, ?, ?)");
if (!$stmt) {
    die("<p style='color: red;'>Error en la preparación de la consulta: " . $db->error . "</p>");
}

$stmt->bind_param("sisss", $id, $user_id, $language, $text, $topic);
$stmt->execute();
$stmt->close();
$db->close();

// Redireccionar a la página principal
header("Location: index.php");
exit;
?>

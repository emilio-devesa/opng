<?php
require("config.php");
require("database.php");
require("highlight.php");
require("sanitize.php");
require("xmlparser.php");

// Inicializar el array de errores
$errors = [];

// Función para obtener una URL corta usando cURL
function short_url($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://is.gd/api.php?longurl=' . urlencode($url));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

    $content = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("Error al acortar URL: " . curl_error($ch));
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    return $content;
}

// Cargar rules.xml usando DOMDocument
$dom = new DOMDocument();
if (!$dom->load(__DIR__ . "/rules.xml")) {
    die("<p style='color: red;'>Error: No se pudo cargar rules.xml.</p>");
}

// Convertir XML a array manualmente
$rules = [];
foreach ($dom->getElementsByTagName('RULE') as $rule) {
    $name = trim($rule->getAttribute('name')); // Se usa trim para evitar espacios
    $rules[$name] = $rule;
}

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
$text = sanitize($_POST['input_text']);
$topic = sanitize($_POST['input_topic']);
$language = trim($_POST['input_language']);

// Verificación exacta con strcmp()
$found = false;
foreach ($rules as $key => $value) {
    if (strcmp($key, $language) === 0) {
        $found = true;
        break;
    }
}

if (!$found) {
    echo "<p style='color: red;'>Error: El lenguaje seleccionado ('" . htmlspecialchars($language) . "') no es válido.</p>";
    echo "<p><a href='pastebin.php'>Volver</a></p>";
    exit;
}

// Conectar a la base de datos
$db = database_connect();
if (!$db) {
    die("<p style='color: red;'>Error: No se pudo conectar a la base de datos.</p>");
}

// Generar un ID único más seguro
$id = bin2hex(random_bytes(8));

// Consulta preparada para evitar inyecciones SQL
$stmt = $db->prepare("INSERT INTO entries (id, language, text, topic) VALUES (?, ?, ?, ?)");
if (!$stmt) {
    die("<p style='color: red;'>Error en la preparación de la consulta: " . $db->error . "</p>");
}

$stmt->bind_param("ssss", $id, $language, $text, $topic);
$stmt->execute();
$stmt->close();

echo "<p style='color: green;'>Entrada agregada con éxito.</p>";

// Construcción segura de la URL
$host = filter_var($_SERVER['HTTP_HOST'], FILTER_SANITIZE_URL);
$dir = rtrim(dirname($_SERVER['PHP_SELF']), '/');
$url = "http://$host$dir/view.php?id=" . urlencode($id);

echo "<p>Link:<br><a href=\"$url\">" . htmlspecialchars($url) . "</a></p>";

// Opcional: Obtener una URL corta
if (!empty($short_url_enable) && $short_url_enable === "yes") {
    echo "<br>";
    $short_url = short_url($url);
    if ($short_url) {
        echo "<p>Short link:<br><a href=\"$short_url\">" . htmlspecialchars($short_url) . "</a></p>";
    }
}

// Cerrar la conexión
$db->close();
?>
<p><a href="index.php">Volver al inicio</a></p>

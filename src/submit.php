<?php
// (v0.8) Procesa el POST creación/respuesta. Refactor: session/csrf/rules centralizadas.
require("includes/session.php");
session_start_secured();
require("includes/csrf.php");
require("includes/rules.php");
require("includes/db.php");

// Protección CSRF (rechaza el POST sin token válido)
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    header("Location: error.php?msg=" . urlencode("Error: invalid CSRF token."));
    exit;
}

// Inicializar el array de errores
$errors = [];

// Obtener ID del usuario autenticado
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

// Validar los datos enviados en el formulario
if (empty($_POST['input_text'])) {
    $errors[] = "The text cannot be empty.";
}
if (empty($_POST['input_language'])) {
    $errors[] = "The language is required.";
}
if (empty($_POST['input_topic'])) {
    $errors[] = "The topic is required.";
}

// Si hay errores, los mostramos y detenemos el script
if (!empty($errors)) {
    echo "<p style='color: red;'><strong>Error:</strong></p><ul>";
    foreach ($errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul><p><a href='pastebin.php'>Back</a></p>";
    exit;
}

// Obtener valores y limpiar entradas
// (fix S3: se almacenan el texto y el topic CRUDOS; el escapado se realiza
// en la capa de presentación, concretamente en view.php)
$text = trim($_POST['input_text']);
$topic = trim($_POST['input_topic']);
$language = trim($_POST['input_language']);

// Verificar si el lenguaje existe en `rules.xml` (allowlist centralizada)
$rules = load_rules();
if (!isset($rules[$language])) {
    header("Location: error.php?msg=" . urlencode("Error: The selected language ('" . $language . "') is not valid."));
    exit;
}

// Conectar a la base de datos
$db = db_connect();
if (!$db) {
    header("Location: error.php?msg=" . urlencode("Error: No se pudo conectar a la base de datos."));
    exit;
}

// Generar un ID único
$id = bin2hex(random_bytes(8));

// Consulta preparada para insertar la entrada
// (fix R3: `user_id` se enlaza explícitamente según exista sesión;
// así el guardián invitado queda como NULL garantizado)
if ($user_id === null) {
    $stmt = $db->prepare("INSERT INTO entries (id, user_id, language, text, topic) VALUES (?, NULL, ?, ?, ?)");
    if (!$stmt) {
        // (S6) el detalle técnico va al log, no al usuario
        error_log("OPNG submit.php: " . $db->error);
        $db->close();
        header("Location: error.php?msg=" . urlencode("Error saving the paste."));
        exit;
    }
    $stmt->bind_param("ssss", $id, $language, $text, $topic);
} else {
    $stmt = $db->prepare("INSERT INTO entries (id, user_id, language, text, topic) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        error_log("OPNG submit.php: " . $db->error);
        $db->close();
        header("Location: error.php?msg=" . urlencode("Error saving the paste."));
        exit;
    }
    $stmt->bind_param("sisss", $id, $user_id, $language, $text, $topic);
}
$stmt->execute();
$stmt->close();
$db->close();

// Redireccionar a la página principal
header("Location: index.php");
exit;
?>

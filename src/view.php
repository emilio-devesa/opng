<?php
require("config.php");
require("database.php");
require("highlight.php");
require("sanitize.php");

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

// Obtener el lenguaje de la entrada
$language = trim($array['language']);

// Cargar rules.xml usando DOMDocument
$dom = new DOMDocument();
if (!$dom->load(__DIR__ . "/rules.xml")) {
    header("Location: error.php?msg=" . urlencode("No se pudo cargar rules.xml"));
    exit;
}

// Convertir XML a array manualmente
$rules = [];
foreach ($dom->getElementsByTagName('RULE') as $rule) {
    $name = $rule->getAttribute('name');
    $rules[$name] = $rule;
}

// Verificar si el lenguaje existe en `rules.xml`
if (!isset($rules[$language])) {
    header("Location: error.php?msg=" . urlencode("Lenguaje no encontrado: " . $language));
    exit;
}

// Aplicar resaltado de sintaxis
/* $highlighted_text = apply_rule($rules[$language], $array['text']); */
function domElementToArray(DOMElement $element) {
    $array = ['attributes' => []];

    // Convertir atributos a array
    foreach ($element->attributes as $attr) {
        $array['attributes'][$attr->name] = $attr->value;
    }

/*     // Convertir nodos hijos a array
    foreach ($element->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE) {
            $array[$child->nodeName][] = domElementToArray($child);
        } elseif ($child->nodeType === XML_TEXT_NODE && trim($child->nodeValue) !== '') {
            $array['value'] = trim($child->nodeValue);
        }
    } */

       // Obtener hijos
       foreach ($element->childNodes as $node) {
        if ($node instanceof DOMText) {
            $text = trim($node->textContent);
            if ($text !== '') {
                $array['value'] = $text;
            }
        } elseif ($node instanceof DOMElement) {
            $array[$node->tagName][] = domElementToArray($node);
        }
    }

    return $array;
}

// Convertir el DOMElement del lenguaje a un array antes de pasarlo a apply_rule()
$ruleArray = domElementToArray($rules[$language]);


$root = $dom->documentElement; // Obtener el nodo raíz
$rules = domElementToArray($root); // Convertir XML a array


$rules = [];
foreach ($dom->getElementsByTagName('RULE') as $rule) {
    $name = $rule->getAttribute('name');
    $rules[$name] = [];

    foreach ($rule->getElementsByTagName('class') as $class) {
        $className = $class->getAttribute('style') ?: 'default';
        $rules[$name][$className] = [];

        foreach ($class->getElementsByTagName('token') as $token) {
            $rules[$name][$className][] = $token->nodeValue;
        }
    }
}


// Aplicar resaltado de sintaxis
$highlighted_text = apply_rule($ruleArray, $array['text']);

$lines = explode("\n", $highlighted_text);

/* // Debug resaltado de sintaxis
echo "<h3>Depuración:</h3>";
echo "<p>Lenguaje detectado: " . htmlspecialchars($language) . "</p>";
echo "<pre>Regla cargada: " . print_r($rules[$language], true) . "</pre>";
echo "<p>Texto original:</p><pre>" . htmlspecialchars($array['text']) . "</pre>";
echo "<p>Texto resaltado:</p><pre>" . $highlighted_text . "</pre>";
exit; */

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ver Entrada - Open Pastebin</title>
    <link rel="stylesheet" href="main.css">
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
                <td>&nbsp;&nbsp;&nbsp;</td>
                <td nowrap align="left">
                    <pre><?php echo $highlighted_text; ?></pre>
                </td>
            </tr>
        </table>

        <h3>Editar Entrada</h3>
        <form method="post" action="submit.php">
            <label>Tema:</label>
            <input type="text" name="input_topic" value="RE: <?php echo htmlspecialchars($array['topic']); ?>"><br>

            <label>Selecciona un lenguaje:</label><br>
            <select name="input_language">
                <?php foreach ($rules as $name => $rule): ?>
                    <option value="<?php echo htmlspecialchars($name); ?>" <?php echo ($name === $language) ? "selected" : ""; ?>>
                        <?php echo htmlspecialchars($name); ?>
                    </option>
                <?php endforeach; ?>
            </select><br>

            <label>Texto:</label><br>
            <textarea name="input_text" rows="25" cols="80"><?php echo htmlspecialchars($array['text'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            <br><br>
            <input type="submit" value="Guardar Cambios">
        </form>
        
        <p><a href="index.php">Volver al inicio</a></p>
    </div>
</body>
</html>

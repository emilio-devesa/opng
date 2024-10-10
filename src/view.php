<?php
// view.php
// Página que recupera y muestra una entrada por ID

require("database.php");
require("highlight.php");
require("xmlparser.php");

// Validar la entrada 'id'
if (!isset($_REQUEST['id']) || !is_numeric($_REQUEST['id'])) {
    die("ID no especificado o no válido.");
}

$id = intval($_REQUEST['id']); // Sanitizar el ID

// Conectar a la base de datos y recuperar la entrada
database_connect();
$array = database_retrieve($id);

if (!$array) {
    die("No se encontró el registro con ID $id.");
}

// Escapar datos para evitar problemas de seguridad XSS
$text = htmlentities($array['Text']);
$topic = htmlentities($array['Topic']);
$language = htmlentities($array['language']);

// Parsear el archivo de reglas XML
$xml_parser = new CXmlParser();
$rules = $xml_parser->parse("rules.xml");

if (!isset($rules['RULE'][$array['Language']])) {
    die("Lenguaje no encontrado en las reglas.");
}

// Aplicar resaltado de sintaxis
$highlighted_text = apply_rule($rules['RULE'][$array['Language']], $text);

// Separar el texto por líneas
$lines = explode("\n", $highlighted_text);
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="content-type" content="text/html; charset=iso-8859-1">
    <title>Open Pastebin</title>
    <style type="text/css" media="all">@import "main.css";</style>
</head>
<body>
    <div id="Content">
        <center>
        <table border="1" cellpadding="2">
            <tr>
                <td>Topic: <?php echo $topic; ?></td>
            </tr>
            <tr>
                <td>Language: <?php echo $rules['RULE'][$array['Language']]['attributes']['NAME']; ?></td>
            </tr>
            <tr>
                <td>ID: <?php echo $array['ID']; ?></td>
            </tr>
            <tr>
                <td>Date: <?php echo $array['Date']; ?></td>
            </tr>
            <tr>
                <td>
                    <table border="0" cellpadding="0" cellspacing="0">
                        <tr>
                            <td align="right">
                                <pre><?php for ($i = 0; $i < count($lines); $i++) echo $i + 1 . "\n"; ?></pre>
                            </td>
                            <td>&nbsp;&nbsp;&nbsp;&nbsp;</td>
                            <td nowrap align="left">
                                <pre><?php echo $highlighted_text; ?></pre>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <br />
        <form method="post" action="submit.php">
            Topic: <input type="text" name="input_topic" value="RE: <?php echo $topic; ?>"><br />
            Select language:<br />
            <select name="input_language">
                <?php
                foreach ($rules['RULE'] as $index => $rule) {
                    $rule_name = htmlentities($rule['attributes']['NAME']);
                    echo "<option value=\"$index\">$rule_name</option>";
                }
                ?>
            </select><br />
            Make changes:<br />
            <textarea name="input_text" rows="25" cols="80"><?php echo $text; ?></textarea>
            <br /><br />
            <input type="submit" value="Submit">
        </form><br /><br />
        <p>Return to <a href="index.php">index</a></p><br />
    </div>
</body>
</html>

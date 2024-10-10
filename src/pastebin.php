<?php
// pastebin.php
// Página principal para ingresar texto y enviarlo a submit.php
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
        <?php
        // Cargar dependencias
        require("highlight.php");
        require("xmlparser.php");

        // Inicializar el parser de XML
        $xml_parser = new CXmlParser();
        $document = $xml_parser->parse("rules.xml");

        // Verificar que el documento XML se haya parseado correctamente
        if (!$document || !isset($document['RULE'])) {
            die("Error al parsear el archivo rules.xml");
        }
        ?>
        <form method="post" action="submit.php">
            <label for="input_topic">Topic:</label>
            <input type="text" id="input_topic" name="input_topic"><br />

            <label for="input_language">Select language:</label><br>
            <select id="input_language" name="input_language">
            <?php
                // Mostrar opciones del archivo XML
                for ($i = 0; $i < count($document['RULE']); $i++) {
                    $rule_name = $document['RULE'][$i]['attributes']['NAME'] ?? 'Unknown';
                    echo "<option value=\"$i\">$rule_name</option>";
                }

                // Mostrar los lenguajes populares
                if (isset($CONF['all_syntax'], $CONF['popular_syntax'])) {
                    foreach ($CONF['all_syntax'] as $code => $name) {
                        if (in_array($code, $CONF['popular_syntax'])) {
                            $sel = ($code == ($page['current_format'] ?? '')) ? 'selected="selected"' : '';
                            echo "<option $sel value=\"$code\">$name</option>";
                        }
                    }
                }

                // Separador
                echo "<option value=\"text\">----------------------------</option>";

                // Mostrar todos los lenguajes
                if (isset($CONF['all_syntax'])) {
                    foreach ($CONF['all_syntax'] as $code => $name) {
                        $sel = ($code == ($page['current_format'] ?? '')) ? 'selected="selected"' : '';
                        if (!in_array($code, $CONF['popular_syntax'])) {
                            echo "<option $sel value=\"$code\">$name</option>";
                        }
                    }
                }
            ?>
            </select><br>

            <label for="input_text">Enter text here:</label><br>
            <textarea id="input_text" name="input_text" rows="25" cols="80"></textarea>
            <br><br>
            <input type="submit" value="Submit">
        </form><br /><br />
        <p>Return to the <a href="index.php">index</a></p><br />
    </div>
</body>
</html>

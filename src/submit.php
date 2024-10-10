<html>
<head>
    <title>Open Pastebin</title>
</head>
<body>
    <?php
    // Función para obtener una URL corta usando cURL
    function short_url($url) {
        $ch = curl_init();
        $timeout = 5;
        curl_setopt($ch, CURLOPT_URL, 'http://is.gd/api.php?longurl=' . urlencode($url));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);

        $content = curl_exec($ch);
        
        // Verificar si hubo errores
        if (curl_errno($ch)) {
            echo "Error al acortar la URL: " . curl_error($ch);
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        return $content;
    }

    require("config.php");
    require("database.php"); // Asume que usa mysqli o PDO
    require("highlight.php");
    require("sanitize.php");

    // Validar las entradas del formulario
    if (!isset($_POST['input_text']) || empty($_POST['input_text'])) {
        die("Input text is not set!");
    }
    if (!isset($_POST['input_language']) || empty($_POST['input_language'])) {
        die("Input language is not set!");
    }
    if (!isset($_POST['input_topic']) || empty($_POST['input_topic'])) {
        die("Input topic is not set!");
    }

    $text = sanitize($_POST['input_text']);
    $lang = sanitize($_POST['input_language']);
    $topic = sanitize($_POST['input_topic']);

    // Conectar a la base de datos
    $db = database_connect(); // Usa mysqli o PDO

    // Generar un ID único
    $id = md5($text); // Esto podría mejorarse con UUID o AUTO_INCREMENT en la base de datos

    // Consulta preparada para evitar inyecciones SQL
    $stmt = $db->prepare("INSERT INTO entries (id, language, text, topic) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("ssss", $id, $lang, $text, $topic);
        $stmt->execute();
        $stmt->close();

        echo "Entry added.<br>";
        
        // Construir la URL
        $url  = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/view.php?id=" . $id;
        echo "Link:<br><a href=\"$url\">$url</a><br>";

        // Opcional: Obtener una URL corta
        if (isset($short_url_enable) && $short_url_enable == "yes") {
            echo "<br>";
            $short_url = short_url($url);
            if ($short_url) {
                echo "Short link:<br><a href=\"$short_url\">$short_url</a>";
            }
        }
    } else {
        echo "Error al insertar la entrada en la base de datos.";
    }

    // Cerrar la conexión
    $db->close();
    ?>
    <p>Return to <a href="index.php">index</a></p>
</body>
</html>

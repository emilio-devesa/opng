<?php
// Conectar a la base de datos usando MySQLi
function database_connect() {
    require("config.php");

    // Crear la conexión
    $conn = new mysqli($mysql_server, $mysql_username, $mysql_password);

    // Verificar la conexión
    if ($conn->connect_error) {
        die("Error de conexión: " . $conn->connect_error);
    }

    // Seleccionar la base de datos, o crearla si no existe
    if (!$conn->select_db($mysql_dbname)) {
        if (!$conn->query("CREATE DATABASE $mysql_dbname")) {
            die("Error al crear la base de datos: " . $conn->error);
        }
        if (!$conn->select_db($mysql_dbname)) {
            die("Error al seleccionar la base de datos después de crearla: " . $conn->error);
        }
    }

    // Crear la tabla `Entries` si no existe
    $query = "
        CREATE TABLE IF NOT EXISTS Entries (
            ID BLOB PRIMARY KEY, 
            Date DATETIME DEFAULT CURRENT_TIMESTAMP,
            Language TINYBLOB, 
            Text BLOB, 
            Topic BLOB
        )
    ";
    if (!$conn->query($query)) {
        die("Error al crear la tabla: " . $conn->error);
    }

    return $conn;
}

// Insertar una entrada en la base de datos usando consultas preparadas
function database_insert($id, $language, $text, $topic) {
    $conn = database_connect();
    $stmt = $conn->prepare("INSERT INTO Entries (ID, Date, Language, Text, Topic) VALUES (?, CURRENT_TIMESTAMP(), ?, ?, ?)");
    if ($stmt === false) {
        die("Error en la preparación de la consulta: " . $conn->error);
    }

    // Asociar los parámetros a la consulta preparada
    $stmt->bind_param("ssss", $id, $language, $text, $topic);

    // Ejecutar la consulta
    if (!$stmt->execute()) {
        die("Error al insertar los datos: " . $stmt->error);
    }

    // Cerrar la conexión
    $stmt->close();
    $conn->close();
}

// Recuperar una entrada por su ID usando consultas preparadas
function database_retrieve($id) {
    $conn = database_connect();
    $stmt = $conn->prepare("SELECT * FROM Entries WHERE ID = ?");
    if ($stmt === false) {
        die("Error en la preparación de la consulta: " . $conn->error);
    }

    // Asociar los parámetros y ejecutar la consulta
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    // Verificar si hay resultados
    if ($result->num_rows == 0) {
        die("La entrada no existe.");
    }

    // Obtener los datos de la entrada
    $array = $result->fetch_assoc();

    // Cerrar la conexión
    $stmt->close();
    $conn->close();

    return $array;
}

// Obtener el número total de entradas
function database_entries() {
    $conn = database_connect();
    $result = $conn->query("SELECT * FROM Entries");

    if (!$result) {
        die("Error al obtener el número de entradas: " . $conn->error);
    }

    $count = $result->num_rows;

    // Cerrar la conexión
    $conn->close();

    return $count;
}
?>

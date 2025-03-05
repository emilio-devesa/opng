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
        if (!$conn->query("CREATE DATABASE $mysql_dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            die("Error al crear la base de datos: " . $conn->error);
        }
        $conn->select_db($mysql_dbname);
    }

    // Crear la tabla `Entries` si no existe
    $query = "
        CREATE TABLE IF NOT EXISTS Entries (
            ID VARCHAR(255) PRIMARY KEY, 
            Date DATETIME DEFAULT CURRENT_TIMESTAMP,
            Language VARCHAR(10), 
            Text TEXT, 
            Topic TEXT
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ";

    if (!$conn->query($query)) {
        die("Error al crear la tabla: " . $conn->error);
    }

    return $conn;
}

// Insertar una entrada en la base de datos usando consultas preparadas
function database_insert($id, $language, $text, $topic) {
    $conn = database_connect();
    $stmt = $conn->prepare("INSERT INTO Entries (ID, Date, Language, Text, Topic) VALUES (?, NOW(), ?, ?, ?)");

    if (!$stmt) {
        die("Error en la preparación de la consulta: " . $conn->error);
    }

    $stmt->bind_param("ssss", $id, $language, $text, $topic);

    if (!$stmt->execute()) {
        die("Error al insertar los datos: " . $stmt->error);
    }

    $stmt->close();
    $conn->close();
}

// Recuperar una entrada por su ID usando consultas preparadas
function database_retrieve($id) {
    $conn = database_connect();
    $stmt = $conn->prepare("SELECT * FROM Entries WHERE ID = ?");

    if (!$stmt) {
        die("Error en la preparación de la consulta: " . $conn->error);
    }

    $stmt->bind_param("s", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 0) {
        return null; // Mejor devolver null en lugar de detener la ejecución
    }

    $entry = $result->fetch_assoc();

    $stmt->close();
    $conn->close();

    return $entry;
}

// Obtener el número total de entradas
function database_entries() {
    $conn = database_connect();
    $result = $conn->query("SELECT COUNT(*) AS count FROM Entries");

    if (!$result) {
        die("Error al obtener el número de entradas: " . $conn->error);
    }

    $count = $result->fetch_assoc()['count'];

    $conn->close();

    return $count;
}
?>

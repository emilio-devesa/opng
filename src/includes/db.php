<?php
// includes/db.php
// Conexión a MySQL usando MySQLi (fix P1/P3: SIN DDL en cada conexión).
// La creación de base de datos/tablas vive en src/install.php.

function db_connect() {
    require(__DIR__ . "/../config.php");

    $conn = new mysqli($mysql_server, $mysql_username, $mysql_password);

    if ($conn->connect_error) {
        // (S6) detalle técnico solo al log; la app lo traduce a mensaje genérico
        error_log("OPNG db.php: MySQL connection failed: " . $conn->connect_error);
        return null;
    }

    if (!$conn->select_db($mysql_dbname)) {
        error_log("OPNG db.php: could not select database '$mysql_dbname': " . $conn->error);
        return null;
    }

    return $conn;
}

// Alias mantenido por compatibilidad con el código existente
// que aún llama a database_connect() desde database.php.
// NOTA: desde v0.8 ESTA FUNCIÓN NO MIGRA (ver src/install.php).

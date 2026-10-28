<?php
// includes/db.php
// Conexión a MySQL usando MySQLi (fix P1/P3: SIN DDL en cada conexión).
// La creación de base de datos/tablas vive en src/install.php.

function db_connect() {
    require(__DIR__ . "/../config.php");

    try {
        $conn = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
        $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $exception) {
        error_log("OPNG db.php: MySQL connection failed: " . $exception->getMessage());
        return null;
    }

    return $conn;
}

// Alias mantenido por compatibilidad con el código existente
// que aún llama a database_connect() desde database.php.
// NOTA: desde v0.8 ESTA FUNCIÓN NO MIGRA (ver src/install.php).

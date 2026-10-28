<?php
// includes/db.php
// Conexión a MySQL usando MySQLi (fix P1/P3: SIN DDL en cada conexión).
// La creación de base de datos/tablas vive en src/install.php.

function db_connect(): ?mysqli {
    require(__DIR__ . "/../config.php");

    $conn = null;
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
        $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $exception) {
        if ($conn instanceof mysqli) {
            $conn->close();
        }
        error_log("OPNG db.php: MySQL connection failed: " . $exception->getMessage());
        return null;
    }

    return $conn;
}

// Alias mantenido por compatibilidad con el código existente
// que aún llama a database_connect() desde database.php.
// NOTA: desde v0.8 ESTA FUNCIÓN NO MIGRA (ver src/install.php).

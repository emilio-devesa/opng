<?php
// install.php
// Instalación de Open Pastebin NG v0.8.
//
// Crea la base de datos, las tablas y los índices necesarios.
// Reemplaza la antigua migración automática de database_connect()
// (que ejecutaba DDL en cada visita — hallazgos P1/P3 del audit v0.7).
//
// Uso:
//   1. Edita config.php con tus credenciales de MySQL.
//   2. Ejecuta este fichero UNA SOLA VEZ:  http://localhost/install.php
//      (o desde CLI:  php install.php)
//   3. BORRA este fichero del servidor al terminar la instalación.
//
// Es idempotente (puede re-ejecutarse sin dañar datos): usa
// CREATE TABLE IF NOT EXISTS y comprueba los índices antes de crearlos.

require(__DIR__ . "/config.php");

header('Content-Type: text/plain; charset=utf-8');
echo "Open Pastebin NG - installer\n============================\n";

$conn = new mysqli($mysql_server, $mysql_username, $mysql_password);

if ($conn->connect_error) {
    echo "ERROR: MySQL connection: " . $conn->connect_error . "\n";
    exit(1);
}

// 1. Base de datos
$db_exists = $conn->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" .
    $conn->real_escape_string($mysql_dbname) . "' LIMIT 1");
if ($db_exists === false || $db_exists->num_rows === 0) {
    if (!$conn->query("CREATE DATABASE `" . $conn->real_escape_string($mysql_dbname) .
        "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        echo "ERROR creating the database: " . $conn->error . "\n";
        exit(1);
    }
    echo "OK: database created ('$mysql_dbname')\n";
} else {
    echo "OK: database already exists ('$mysql_dbname')\n";
}

if (!$conn->select_db($mysql_dbname)) {
    echo "ERROR selecting the database: " . $conn->error . "\n";
    exit(1);
}

// 2. Tabla Users
$query = "
    CREATE TABLE IF NOT EXISTS Users (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        Username VARCHAR(50) UNIQUE NOT NULL,
        Email VARCHAR(100) UNIQUE NOT NULL,
        Password_hash VARCHAR(255) NOT NULL,
        Created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
        reset_token VARCHAR(255) NULL,
        reset_token_expires DATETIME NULL
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
";
if (!$conn->query($query)) {
    echo "ERROR creating table Users: " . $conn->error . "\n";
    exit(1);
}
echo "OK: table Users\n";

// 3. Tabla Entries
$query = "
    CREATE TABLE IF NOT EXISTS Entries (
        ID VARCHAR(255) PRIMARY KEY,
        user_id INT,
        Date DATETIME DEFAULT CURRENT_TIMESTAMP,
        Language VARCHAR(20),
        Text TEXT,
        Topic TEXT
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
";
if (!$conn->query($query)) {
    echo "ERROR creating table Entries: " . $conn->error . "\n";
    exit(1);
}
echo "OK: table Entries\n";

// 4. Tabla Rate_limits (S5/R8: rate limiting persistente por IP/cuenta).
//    La clave se guarda hasheada (SHA-256), nunca la IP/email en claro.
$query = "
    CREATE TABLE IF NOT EXISTS rate_limits (
        action VARCHAR(32) NOT NULL,
        key_hash CHAR(64) NOT NULL,
        window_start INT UNSIGNED NOT NULL,
        hits INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (action, key_hash),
        KEY idx_rate_window (window_start)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
";
if (!$conn->query($query)) {
    echo "ERROR creating table rate_limits: " . $conn->error . "\n";
    exit(1);
}
echo "OK: table rate_limits\n";

// 5. Índice sobre Entries.Date (P2: listado ORDER BY date DESC sin filesort).
//    MySQL no soporta CREATE INDEX IF NOT EXISTS: comprobar information_schema.
$idx_name = 'idx_entries_date';
$idx_exists = $conn->query("SELECT INDEX_NAME FROM information_schema.STATISTICS " .
    "WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($mysql_dbname) . "' " .
    "AND TABLE_NAME = 'Entries' AND INDEX_NAME = '" . $idx_name . "' LIMIT 1");
if ($idx_exists === false || $idx_exists->num_rows === 0) {
    if (!$conn->query("CREATE INDEX `" . $idx_name . "` ON Entries (Date)")) {
        echo "WARNING: could not create index $idx_name: " . $conn->error . "\n";
    } else {
        echo "OK: index $idx_name creado\n";
    }
} else {
    echo "OK: index $idx_name ya existe\n";
}

// 6. Registros de usuarios (opcional, NO automático)
echo "\nPost-install notes:\n";
echo " - New registrations are created with the 'user' role\n";
echo " - To create an administrator:\n";
echo "   1) Register on the website (auth/register.php)\n";
echo "   2) Promote the account: UPDATE Users SET role='admin' WHERE Email='your_email';\n";
echo "\nRemember to delete install.php from the server!\n";

$conn->close();

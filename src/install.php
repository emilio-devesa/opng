<?php
// CLI-only schema installer. Create the target database and migration account
// before running this script; runtime credentials are never used for DDL.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/config.php';
$database_name = getenv('OPNG_DB_NAME') ?: $mysql_dbname;

function install_fail(string $message, ?string $detail = null): never
{
    if ($detail !== null) {
        error_log('OPNG installer: ' . $detail);
    }

    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function install_table_names(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    );

    $names = [];
    while ($row = $result->fetch_assoc()) {
        $names[] = $row['TABLE_NAME'];
    }
    $result->free();

    return $names;
}

function normalize_table_name(mysqli $conn, string $target, string $legacy): bool
{
    $matches = array_values(array_filter(
        install_table_names($conn),
        static fn (string $name): bool => strcasecmp($name, $target) === 0
    ));

    if ($matches === []) {
        return false;
    }

    if (count($matches) === 1 && $matches[0] === $target) {
        return true;
    }

    if (count($matches) !== 1 || $matches[0] !== $legacy) {
        install_fail(
            "Ambiguous table names for '{$target}'. No tables were renamed.",
            'Unexpected case variants found: ' . implode(', ', $matches)
        );
    }

    $temporary = '__opng_migrate_' . $target . '_' . bin2hex(random_bytes(6));
    $conn->query("RENAME TABLE `{$legacy}` TO `{$temporary}`");

    try {
        $conn->query("RENAME TABLE `{$temporary}` TO `{$target}`");
    } catch (mysqli_sql_exception $exception) {
        try {
            $conn->query("RENAME TABLE `{$temporary}` TO `{$legacy}`");
        } catch (mysqli_sql_exception $rollback_exception) {
            error_log(
                'OPNG installer: table rename rollback failed; data remains in ' .
                $temporary . ': ' . $rollback_exception->getMessage()
            );
        }

        install_fail(
            "Could not safely rename '{$legacy}' to '{$target}'.",
            $exception->getMessage()
        );
    }

    return true;
}

if (!preg_match('/\A[A-Za-z0-9_]+\z/', $database_name)) {
    install_fail('The configured database name is invalid.');
}

$migration_user = getenv('OPNG_INSTALL_DB_USER');
$migration_password = getenv('OPNG_INSTALL_DB_PASS');
if ($migration_user === false || $migration_user === '') {
    install_fail('Set OPNG_INSTALL_DB_USER and OPNG_INSTALL_DB_PASS for the migration account.');
}

$migration_host = getenv('OPNG_INSTALL_DB_HOST') ?: $mysql_server;
$migration_port_value = getenv('OPNG_INSTALL_DB_PORT');
$migration_port = $migration_port_value === false
    ? (int) ini_get('mysqli.default_port')
    : filter_var($migration_port_value, FILTER_VALIDATE_INT);

if ($migration_port === false || $migration_port < 1 || $migration_port > 65535) {
    install_fail('The configured migration database port is invalid.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(
        $migration_host,
        $migration_user,
        $migration_password === false ? '' : $migration_password,
        $database_name,
        $migration_port
    );
    $conn->set_charset('utf8mb4');

    foreach ([
        'users' => 'Users',
        'entries' => 'Entries',
        'rate_limits' => 'Rate_limits',
    ] as $target => $legacy) {
        normalize_table_name($conn, $target, $legacy);
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
            reset_token VARCHAR(255) NULL,
            reset_token_expires DATETIME NULL
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    echo "OK: table users\n";

    $conn->query("
        CREATE TABLE IF NOT EXISTS entries (
            id VARCHAR(255) PRIMARY KEY,
            user_id INT,
            date DATETIME DEFAULT CURRENT_TIMESTAMP,
            language VARCHAR(20),
            text MEDIUMTEXT,
            topic TEXT
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    echo "OK: table entries\n";

    $text_type = $conn->query(
        "SELECT DATA_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'entries'
           AND COLUMN_NAME = 'text'"
    )->fetch_assoc();

    if ($text_type === null) {
        install_fail("The 'entries.text' column is missing; schema migration stopped.");
    }
    if ($text_type['DATA_TYPE'] === 'text') {
        $conn->query('ALTER TABLE entries MODIFY `text` MEDIUMTEXT');
        echo "OK: widened entries.text to MEDIUMTEXT\n";
    } elseif ($text_type['DATA_TYPE'] !== 'mediumtext') {
        install_fail(
            "Unsupported type for 'entries.text'; schema migration stopped.",
            'Found column type: ' . $text_type['DATA_TYPE']
        );
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS rate_limits (
            action VARCHAR(32) NOT NULL,
            key_hash CHAR(64) NOT NULL,
            window_start INT UNSIGNED NOT NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (action, key_hash),
            KEY idx_rate_window (window_start)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    echo "OK: table rate_limits\n";

    $index = $conn->query(
        "SELECT INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'entries'
           AND INDEX_NAME = 'idx_entries_date'
         LIMIT 1"
    );

    if ($index->num_rows === 0) {
        $conn->query('CREATE INDEX `idx_entries_date` ON entries (`date`)');
        echo "OK: index idx_entries_date\n";
    } else {
        echo "OK: index idx_entries_date already exists\n";
    }

    echo "\nSchema installation complete.\n";
    echo "The target database was not created or dropped by this installer.\n";
    echo "Revoke the migration account when installation is complete.\n";
    $conn->close();
} catch (mysqli_sql_exception $exception) {
    install_fail('Database operation failed; check the configured migration account and server log.', $exception->getMessage());
}

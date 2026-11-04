<?php
// CLI-only schema installer. Create the target database and migration account
// before running this script; runtime credentials are never used for DDL.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/config.php';
require __DIR__ . '/includes/schema.php';
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

function install_column_info(mysqli $conn, string $table, string $column): ?array
{
    $stmt = $conn->prepare(
        "SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH, COLUMN_KEY
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : null;
}

function install_ensure_column(
    mysqli $conn,
    string $table,
    string $column,
    string $definition,
    array &$report
): array {
    $info = install_column_info($conn, $table, $column);
    if ($info !== null) {
        return $info;
    }

    $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    $report[] = "added {$table}.{$column}";
    echo "OK: added {$table}.{$column}\n";

    $info = install_column_info($conn, $table, $column);
    if ($info === null) {
        install_fail("Could not add the '{$table}.{$column}' column; schema migration stopped.");
    }

    return $info;
}

function install_index_exists(mysqli $conn, string $table, string $index_name): bool
{
    $stmt = $conn->prepare(
        "SELECT 1
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
         LIMIT 1"
    );
    $stmt->bind_param('ss', $table, $index_name);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

function install_ensure_unique_column_index(
    mysqli $conn,
    string $table,
    string $column,
    string $index_name,
    array &$report
): void {
    $stmt = $conn->prepare(
        "SELECT INDEX_NAME, COLUMN_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0
         ORDER BY INDEX_NAME, SEQ_IN_INDEX"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $unique_indexes = [];
    while ($row = $result->fetch_assoc()) {
        $unique_indexes[$row['INDEX_NAME']][] = $row['COLUMN_NAME'];
    }
    $result->free();
    $stmt->close();

    foreach ($unique_indexes as $columns) {
        if (count($columns) === 1 && strcasecmp($columns[0], $column) === 0) {
            return;
        }
    }

    $duplicate = $conn->query(
        "SELECT `{$column}` FROM `{$table}`
         GROUP BY `{$column}` HAVING COUNT(*) > 1 LIMIT 1"
    )->fetch_assoc();
    if ($duplicate !== null) {
        install_fail(
            "The '{$table}.{$column}' column contains duplicates; a unique index is required.",
            'Resolve the duplicate values and rerun the installer.'
        );
    }

    $conn->query("ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$index_name}` (`{$column}`)");
    $report[] = "added unique index {$index_name} on {$table}.{$column}";
    echo "OK: added unique index {$index_name} on {$table}.{$column}\n";
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

$migration_report = [];

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
            session_version INT UNSIGNED NOT NULL DEFAULT 0,
            reset_token VARCHAR(255) NULL,
            reset_token_expires DATETIME NULL
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    echo "OK: table users\n";

    // Legacy installations may predate role/reset columns. CREATE TABLE IF NOT
    // EXISTS does nothing for them, so add the missing columns idempotently and
    // fail with an actionable message when a core column cannot be migrated.
    foreach (['id', 'username', 'email', 'password_hash'] as $core_column) {
        if (install_column_info($conn, 'users', $core_column) === null) {
            install_fail(
                "The 'users.{$core_column}' column is missing; recreate the table or migrate it manually."
            );
        }
    }

    $users_column_info = [];
    foreach ([
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'role' => "ENUM('admin', 'user') NOT NULL DEFAULT 'user'",
        'session_version' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'reset_token' => 'VARCHAR(255) NULL',
        'reset_token_expires' => 'DATETIME NULL',
    ] as $column => $definition) {
        $users_column_info[$column] = install_ensure_column(
            $conn,
            'users',
            $column,
            $definition,
            $migration_report
        );
    }

    if (!in_array($users_column_info['created_at']['DATA_TYPE'], ['timestamp', 'datetime'], true)) {
        install_fail("Unsupported type for 'users.created_at'; schema migration stopped.");
    }
    if (
        $users_column_info['role']['DATA_TYPE'] !== 'enum'
        || !str_contains($users_column_info['role']['COLUMN_TYPE'], "'admin'")
        || !str_contains($users_column_info['role']['COLUMN_TYPE'], "'user'")
    ) {
        install_fail("Unsupported type for 'users.role'; schema migration stopped.");
    }
    if (!in_array($users_column_info['reset_token']['DATA_TYPE'], ['varchar', 'char'], true)) {
        install_fail("Unsupported type for 'users.reset_token'; schema migration stopped.");
    }
    if (!in_array($users_column_info['reset_token_expires']['DATA_TYPE'], ['datetime', 'timestamp'], true)) {
        install_fail("Unsupported type for 'users.reset_token_expires'; schema migration stopped.");
    }
    if (!in_array(
        $users_column_info['session_version']['DATA_TYPE'],
        ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'],
        true
    )) {
        install_fail("Unsupported type for 'users.session_version'; schema migration stopped.");
    }

    $password_hash_column = install_column_info($conn, 'users', 'password_hash');
    if ($password_hash_column === null || !in_array($password_hash_column['DATA_TYPE'], ['varchar', 'char'], true)) {
        install_fail("Unsupported type for 'users.password_hash'; schema migration stopped.");
    }

    if (!install_index_exists($conn, 'users', 'PRIMARY')) {
        install_fail(
            "The 'users' table has no primary key; schema migration stopped.",
            'Add a primary key on users.id or recreate the table.'
        );
    }
    install_ensure_unique_column_index(
        $conn,
        'users',
        'username',
        'uniq_users_username',
        $migration_report
    );
    install_ensure_unique_column_index(
        $conn,
        'users',
        'email',
        'uniq_users_email',
        $migration_report
    );

    // Admin search and listing order by creation date; role filters are part of
    // the dashboard feature set. Both indexes are created idempotently.
    foreach ([
        'idx_users_created' => 'CREATE INDEX `idx_users_created` ON users (`created_at`, `id`)',
        'idx_users_role' => 'CREATE INDEX `idx_users_role` ON users (`role`, `created_at`)',
    ] as $index_name => $create_sql) {
        if (!install_index_exists($conn, 'users', $index_name)) {
            $conn->query($create_sql);
            echo "OK: index {$index_name}\n";
        }
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS entries (
            id VARCHAR(255) PRIMARY KEY,
            user_id INT,
            date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            language VARCHAR(20) NOT NULL DEFAULT '',
            text MEDIUMTEXT,
            topic VARCHAR(255) NOT NULL DEFAULT ''
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    echo "OK: table entries\n";

    // Validate the storage engine, charset and core columns so an upgraded
    // database matches the assumptions behind the listing indexes and quota.
    $entries_table = $conn->query(
        "SELECT ENGINE, TABLE_COLLATION
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'entries'"
    )->fetch_assoc();
    if ($entries_table === null) {
        install_fail("The 'entries' table metadata is unavailable; schema migration stopped.");
    }
    if (strcasecmp((string) ($entries_table['ENGINE'] ?? ''), 'InnoDB') !== 0) {
        $conn->query('ALTER TABLE entries ENGINE=InnoDB');
        $migration_report[] = 'converted entries to InnoDB';
        echo "OK: converted entries to InnoDB\n";
    }
    $entries_collation = (string) ($entries_table['TABLE_COLLATION'] ?? '');
    if (!str_starts_with($entries_collation, 'utf8mb4_')) {
        $conn->query('ALTER TABLE entries CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $migration_report[] = 'converted entries to utf8mb4_unicode_ci';
        echo "OK: converted entries to utf8mb4_unicode_ci\n";
    }

    if (!install_index_exists($conn, 'entries', 'PRIMARY')) {
        install_fail(
            "The 'entries' table has no primary key; schema migration stopped.",
            'Add a primary key on entries.id or recreate the table.'
        );
    }
    $entry_id_column = install_column_info($conn, 'entries', 'id');
    if ($entry_id_column === null || !in_array($entry_id_column['DATA_TYPE'], ['varchar', 'char'], true)) {
        install_fail("Unsupported type for 'entries.id'; schema migration stopped.");
    }
    $entry_user_column = install_column_info($conn, 'entries', 'user_id');
    if ($entry_user_column === null) {
        $conn->query('ALTER TABLE entries ADD COLUMN `user_id` INT NULL');
        $migration_report[] = 'added entries.user_id';
        echo "OK: added entries.user_id\n";
    } elseif (!in_array(
        $entry_user_column['DATA_TYPE'],
        ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'],
        true
    )) {
        install_fail("Unsupported type for 'entries.user_id'; schema migration stopped.");
    }

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

    // Ordering by entries.date only uses idx_entries_cursor when the column is
    // NOT NULL; COALESCE() in the ORDER BY forced a full scan + filesort.
    $date_column = $conn->query(
        "SELECT DATA_TYPE, IS_NULLABLE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'entries'
           AND COLUMN_NAME = 'date'"
    )->fetch_assoc();
    if ($date_column === null) {
        install_fail("The 'entries.date' column is missing; schema migration stopped.");
    }
    if ($date_column['DATA_TYPE'] !== 'datetime') {
        install_fail(
            "Unsupported type for 'entries.date'; schema migration stopped.",
            'Found column type: ' . $date_column['DATA_TYPE']
        );
    }
    if ($date_column['IS_NULLABLE'] === 'YES') {
        $conn->query("UPDATE entries SET date = '1970-01-01 00:00:00' WHERE date IS NULL");
        $conn->query('ALTER TABLE entries MODIFY `date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        echo "OK: made entries.date NOT NULL (legacy NULLs moved to 1970-01-01)\n";
    }

    // topic and language are required by the application. Storing them as
    // NOT NULL VARCHAR columns lets the listing indexes serve ORDER BY without
    // COALESCE() and keeps keyset pagination NULL-free.
    $topic_column = $conn->query(
        "SELECT DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'entries'
           AND COLUMN_NAME = 'topic'"
    )->fetch_assoc();
    if ($topic_column === null) {
        install_fail("The 'entries.topic' column is missing; schema migration stopped.");
    }
    if (in_array($topic_column['DATA_TYPE'], ['text', 'mediumtext', 'longtext'], true)) {
        $long_topics = $conn->query(
            'SELECT COUNT(*) AS total FROM entries WHERE OCTET_LENGTH(topic) > 255'
        )->fetch_assoc();
        if ($long_topics === null || (int) $long_topics['total'] > 0) {
            install_fail(
                "entries.topic contains values longer than 255 bytes; schema migration stopped.",
                'Rows over the limit: ' . (string) ($long_topics['total'] ?? 'unknown')
            );
        }
        $conn->query("UPDATE entries SET topic = '' WHERE topic IS NULL");
        $conn->query('ALTER TABLE entries MODIFY `topic` VARCHAR(255) NOT NULL');
        echo "OK: converted entries.topic to VARCHAR(255) NOT NULL\n";
    } elseif ($topic_column['DATA_TYPE'] !== 'varchar' || (int) $topic_column['CHARACTER_MAXIMUM_LENGTH'] !== 255) {
        install_fail(
            "Unsupported type for 'entries.topic'; schema migration stopped.",
            'Found column type: ' . $topic_column['DATA_TYPE']
        );
    } elseif ($topic_column['IS_NULLABLE'] === 'YES') {
        $conn->query("UPDATE entries SET topic = '' WHERE topic IS NULL");
        $conn->query('ALTER TABLE entries MODIFY `topic` VARCHAR(255) NOT NULL');
        echo "OK: made entries.topic NOT NULL\n";
    }

    $language_column = $conn->query(
        "SELECT DATA_TYPE, IS_NULLABLE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'entries'
           AND COLUMN_NAME = 'language'"
    )->fetch_assoc();
    if ($language_column === null) {
        install_fail("The 'entries.language' column is missing; schema migration stopped.");
    }
    if ($language_column['DATA_TYPE'] !== 'varchar') {
        install_fail(
            "Unsupported type for 'entries.language'; schema migration stopped.",
            'Found column type: ' . $language_column['DATA_TYPE']
        );
    }
    if ($language_column['IS_NULLABLE'] === 'YES') {
        $conn->query("UPDATE entries SET language = '' WHERE language IS NULL");
        $conn->query("ALTER TABLE entries MODIFY `language` VARCHAR(20) NOT NULL DEFAULT ''");
        echo "OK: made entries.language NOT NULL\n";
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

    $conn->query("
        CREATE TABLE IF NOT EXISTS admin_metrics_cache (
            cache_key VARCHAR(32) NOT NULL PRIMARY KEY,
            payload MEDIUMTEXT NOT NULL,
            expires_at BIGINT UNSIGNED NOT NULL,
            KEY idx_admin_metrics_expiry (expires_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    echo "OK: table admin_metrics_cache\n";

    $conn->query("
        CREATE TABLE IF NOT EXISTS storage_counters (
            counter_key VARCHAR(32) NOT NULL PRIMARY KEY,
            used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    $conn->query(
        "INSERT IGNORE INTO storage_counters (counter_key, used_bytes)
         SELECT 'global', COALESCE(SUM(OCTET_LENGTH(text)), 0) FROM entries"
    );
    echo "OK: table storage_counters\n";

    $conn->query("
        CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_id INT NULL,
            action VARCHAR(32) NOT NULL,
            target VARCHAR(255) NOT NULL DEFAULT '',
            ip_hash CHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_audit_created (created_at, id),
            KEY idx_audit_actor (actor_id)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    echo "OK: table audit_log\n";

    $required_indexes = [
        'idx_entries_date' => 'CREATE INDEX `idx_entries_date` ON entries (`date`)',
        'idx_entries_cursor' => 'CREATE INDEX `idx_entries_cursor` ON entries (`date`, `id`)',
        'idx_entries_user' => 'CREATE INDEX `idx_entries_user` ON entries (`user_id`)',
        'idx_entries_topic' => 'CREATE INDEX `idx_entries_topic` ON entries (`topic`, `id`)',
        'idx_entries_language' => 'CREATE INDEX `idx_entries_language` ON entries (`language`, `id`)',
        'ft_entries_topic' => 'CREATE FULLTEXT INDEX `ft_entries_topic` ON entries (`topic`)',
    ];
    foreach ($required_indexes as $index_name => $create_sql) {
        if (!install_index_exists($conn, 'entries', $index_name)) {
            $conn->query($create_sql);
            $migration_report[] = "created index {$index_name}";
            echo "OK: index {$index_name}\n";
        } else {
            echo "OK: index {$index_name} already exists\n";
        }
    }

    // Apply versioned migrations after the idempotent bootstrap. Fresh
    // installations and upgrades both end with the same recorded version.
    $applied_migrations = schema_apply_migrations($conn, __DIR__ . '/../database/migrations');
    foreach ($applied_migrations as $applied_version) {
        $migration_report[] = "applied migration {$applied_version}";
        echo "OK: applied migration {$applied_version}\n";
    }
    echo 'OK: schema version ' . (schema_current_version($conn) ?? 'none') . "\n";

    echo "\nMigration report:\n";
    if ($migration_report === []) {
        echo "- No schema changes were needed; required columns and indexes are present.\n";
    } else {
        foreach ($migration_report as $migration_note) {
            echo "- {$migration_note}\n";
        }
    }

    echo "\nSchema installation complete.\n";
    echo "The target database was not created or dropped by this installer.\n";
    echo "Revoke the migration account when installation is complete.\n";
    echo "Then run the startup doctor: php tools/doctor.php\n";
    $conn->close();
} catch (mysqli_sql_exception $exception) {
    install_fail('Database operation failed; check the configured migration account and server log.', $exception->getMessage());
}

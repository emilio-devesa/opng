<?php
/**
 * Schema drift test (F2).
 *
 * Installs the canonical database/schema.sql into one disposable database and
 * runs the real src/install.php into another, then compares every table with
 * SHOW CREATE TABLE. A difference means the canonical DDL and the installer
 * have diverged and a release artifact would document the wrong schema.
 *
 * Opt-in only: requires OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 and an account
 * that can create/drop two throwaway databases. Never point it at production.
 */

require __DIR__ . '/../src/includes/schema.php';

function schema_drift_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function schema_drift_pass(string $message): void
{
    echo "PASS: {$message}\n";
}

if (getenv('OPNG_TEST_ALLOW_DATABASE_FIXTURES') !== '1') {
    echo "SKIP: set OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 to run the schema drift test.\n";
    exit(0);
}

$host = getenv('OPNG_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('OPNG_TEST_DB_PORT') ?: 3306);
$user = getenv('OPNG_TEST_DB_USER') ?: '';
$password = getenv('OPNG_TEST_DB_PASS') ?: '';

if ($user === '') {
    fwrite(STDERR, "SKIP: set OPNG_TEST_DB_USER (and OPNG_TEST_DB_PASS) to run the schema drift test.\n");
    exit(0);
}

$root = dirname(__DIR__);
$config_path = $root . '/src/config.php';
if (!is_file($config_path)) {
    schema_drift_fail('src/config.php is missing; copy src/config.example.php first.');
}

$suffix = strtolower(bin2hex(random_bytes(4)));
$schema_db = 'opng_drift_schema_' . $suffix;
$install_db = 'opng_drift_install_' . $suffix;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$admin = null;
$db = null;
try {
    $admin = new mysqli($host, $user, $password, null, $port);
    $admin->set_charset('utf8mb4');
    foreach ([$schema_db, $install_db] as $database) {
        $admin->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    $schema_sql = file_get_contents($root . '/database/schema.sql');
    if (!is_string($schema_sql) || trim($schema_sql) === '') {
        schema_drift_fail('database/schema.sql is empty or unreadable');
    }

    $db = new mysqli($host, $user, $password, $schema_db, $port);
    $db->set_charset('utf8mb4');
    foreach (schema_split_sql($schema_sql) as $statement) {
        $db->query($statement);
    }
    $schema_tables = schema_drift_tables($db);
    $schema_definitions = schema_drift_definitions($db, $schema_tables);
    $schema_versions = schema_drift_versions($db);
    $db->close();
    $db = null;

    if (count($schema_tables) !== 7) {
        schema_drift_fail('database/schema.sql did not create the seven application tables');
    }
    if (array_diff(['0001_baseline', '0002_foreign_keys', '0003_admin_metrics_expires_bigint', '0004_entry_threads'], $schema_versions) !== []) {
        schema_drift_fail('database/schema.sql did not record every migration version');
    }
    schema_drift_pass('database/schema.sql installs the v1.0 end state and records every migration');

    $environment = getenv();
    $environment['OPNG_DB_HOST'] = $host . ':' . $port;
    $environment['OPNG_DB_USER'] = $user;
    $environment['OPNG_DB_PASS'] = $password;
    $environment['OPNG_DB_NAME'] = $install_db;
    $environment['OPNG_INSTALL_DB_HOST'] = $host;
    $environment['OPNG_INSTALL_DB_PORT'] = (string) $port;
    $environment['OPNG_INSTALL_DB_USER'] = $user;
    $environment['OPNG_INSTALL_DB_PASS'] = $password;

    $command = [PHP_BINARY, $root . '/src/install.php'];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $root, $environment);
    if (!is_resource($process)) {
        schema_drift_fail('the installer process could not be started');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);
    if ($exit_code !== 0) {
        schema_drift_fail(
            'src/install.php failed with exit ' . $exit_code . ': '
            . trim((string) $stderr . ' ' . (string) $stdout)
        );
    }
    schema_drift_pass('src/install.php applies the same migrations through the CLI path');

    $db = new mysqli($host, $user, $password, $install_db, $port);
    $db->set_charset('utf8mb4');
    $install_tables = schema_drift_tables($db);
    $install_definitions = schema_drift_definitions($db, $install_tables);
    $install_versions = schema_drift_versions($db);
    $db->close();
    $db = null;

    if ($schema_tables !== $install_tables) {
        schema_drift_fail(
            'table names differ. schema.sql: ' . implode(', ', $schema_tables)
            . '; installer: ' . implode(', ', $install_tables)
        );
    }
    if ($schema_versions !== $install_versions) {
        schema_drift_fail(
            'migration versions differ. schema.sql: ' . implode(', ', $schema_versions)
            . '; installer: ' . implode(', ', $install_versions)
        );
    }

    foreach ($schema_definitions as $table => $definition) {
        if (!isset($install_definitions[$table])) {
            schema_drift_fail("the installer did not create the {$table} table");
        }
        if ($definition !== $install_definitions[$table]) {
            fwrite(STDERR, "FAIL: SHOW CREATE TABLE differs for {$table}\n");
            fwrite(STDERR, "--- database/schema.sql ---\n{$definition}\n");
            fwrite(STDERR, "--- src/install.php ---\n{$install_definitions[$table]}\n");
            exit(1);
        }
    }
    schema_drift_pass('SHOW CREATE TABLE is identical for every table after both installation paths');

    $admin->query("DROP DATABASE `{$schema_db}`");
    $admin->query("DROP DATABASE `{$install_db}`");
    $admin->close();
} catch (mysqli_sql_exception | RuntimeException $exception) {
    if (isset($db) && $db instanceof mysqli) {
        $db->close();
    }
    if (isset($admin) && $admin instanceof mysqli) {
        foreach ([$schema_db, $install_db] as $database) {
            try {
                $admin->query("DROP DATABASE IF EXISTS `{$database}`");
            } catch (mysqli_sql_exception) {
                // Best-effort cleanup; the original failure is more useful.
            }
        }
        $admin->close();
    }
    schema_drift_fail($exception->getMessage());
}

echo "Schema drift passed: database/schema.sql and src/install.php produce the same tables.\n";

/**
 * @return list<string>
 */
function schema_drift_tables(mysqli $db): array
{
    $result = $db->query(
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY TABLE_NAME ASC"
    );
    $tables = [];
    while ($row = $result->fetch_assoc()) {
        if (is_string($row['TABLE_NAME'] ?? null)) {
            $tables[] = $row['TABLE_NAME'];
        }
    }
    $result->free();

    return $tables;
}

/**
 * @param list<string> $tables
 * @return array<string, string>
 */
function schema_drift_definitions(mysqli $db, array $tables): array
{
    $definitions = [];
    foreach ($tables as $table) {
        $row = $db->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch_assoc();
        $definition = is_array($row) ? ($row['Create Table'] ?? null) : null;
        if (!is_string($definition)) {
            throw new RuntimeException("SHOW CREATE TABLE failed for {$table}.");
        }
        $definitions[$table] = $definition;
    }

    return $definitions;
}

/**
 * @return list<string>
 */
function schema_drift_versions(mysqli $db): array
{
    $result = $db->query('SELECT version FROM schema_migrations ORDER BY version ASC');
    $versions = [];
    while ($row = $result->fetch_assoc()) {
        if (is_string($row['version'] ?? null)) {
            $versions[] = $row['version'];
        }
    }
    $result->free();

    return $versions;
}

<?php
/**
 * Startup configuration doctor for Open Pastebin NG (SEC-17).
 *
 * Usage (from the repository root):
 *   php tools/doctor.php [--json] [--strict]
 *
 * - CLI only.
 * - Checks config.php, credentials, connection, schema version,
 *   display_errors, OPNG_DEV_RESET, installer credentials and HTTPS.
 * - Exits with code 1 on failures (or warnings with --strict). It changes nothing.
 * - `--json` produces output suitable for a container entrypoint.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$options = ['json' => false, 'strict' => false];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--json') {
        $options['json'] = true;
    } elseif ($argument === '--strict') {
        $options['strict'] = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php tools/doctor.php [--json] [--strict]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: {$argument}\n");
        fwrite(STDERR, "Usage: php tools/doctor.php [--json] [--strict]\n");
        exit(2);
    }
}

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/includes/doctor.php';
require __DIR__ . '/../src/includes/version.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$connected = false;
$server_info = '';
$current_schema = null;
$applied = [];

try {
    $db = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
    $db->set_charset('utf8mb4');
    $connected = true;
    $server_info = $db->server_info;

    try {
        $result = $db->query('SELECT version FROM schema_migrations ORDER BY version ASC');
        while ($row = $result->fetch_assoc()) {
            if (is_string($row['version'] ?? null)) {
                $applied[] = $row['version'];
            }
        }
        $result->free();
        $current_schema = $applied === [] ? null : $applied[count($applied) - 1];
    } catch (mysqli_sql_exception $exception) {
        // A missing schema_migrations table is reported by the schema check.
        $current_schema = null;
        $applied = [];
    }

    $db->close();
} catch (mysqli_sql_exception $exception) {
    // The connection check reports the failure without echoing credentials.
    $connected = false;
}

$install_user = getenv('OPNG_INSTALL_DB_USER');
$install_name = getenv('OPNG_INSTALL_DB_NAME');
$install_creds = (is_string($install_user) && $install_user !== '')
    || (is_string($install_name) && $install_name !== '');

$context = [
    'config_path' => realpath(__DIR__ . '/../src/config.php') ?: null,
    'config_password' => is_string($mysql_password) ? $mysql_password : '',
    'database' => [
        'connected' => $connected,
        'server_info' => $server_info,
        'current_schema' => $current_schema,
        'applied' => $applied,
    ],
    'migrations_dir' => __DIR__ . '/../database/migrations',
    'display_errors' => (string) ini_get('display_errors'),
    'sapi' => PHP_SAPI,
    'dev_reset' => getenv('OPNG_DEV_RESET') === false ? null : (string) getenv('OPNG_DEV_RESET'),
    'install_creds' => $install_creds,
    'https' => opng_doctor_https_state(getenv('OPNG_PUBLIC_URL') ?: null),
];

$results = opng_doctor_checks($context);
$summary = opng_doctor_summary($results);
$has_failure = $summary[OPNG_DOCTOR_FAIL] > 0;
$has_warning = $summary[OPNG_DOCTOR_WARN] > 0;
$exit_code = $has_failure || ($options['strict'] && $has_warning) ? 1 : 0;

if ($options['json']) {
    $status = $has_failure ? 'error' : ($has_warning ? 'warning' : 'ok');
    echo json_encode([
        'version' => OPNG_VERSION,
        'status' => $status,
        'summary' => $summary,
        'checks' => $results,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit($exit_code);
}

echo 'Open Pastebin NG doctor ' . OPNG_VERSION . "\n\n";
foreach ($results as $result) {
    $label = strtoupper($result['status']);
    printf("[%-4s] %s: %s\n", $label, $result['label'], $result['detail']);
}

printf(
    "\nSummary: %d ok, %d warning(s), %d failure(s), %d skipped\n",
    $summary[OPNG_DOCTOR_OK],
    $summary[OPNG_DOCTOR_WARN],
    $summary[OPNG_DOCTOR_FAIL],
    $summary[OPNG_DOCTOR_SKIP]
);

if ($has_failure) {
    fwrite(STDERR, "Doctor found configuration failures. Fix them before serving traffic.\n");
} elseif ($has_warning) {
    fwrite(STDERR, "Doctor found configuration warnings. Review them before production.\n");
} else {
    echo "Doctor checks passed.\n";
}

exit($exit_code);

<?php

require __DIR__ . '/../src/includes/doctor.php';

$root = dirname(__DIR__);
$failures = [];

function expect_doctor(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

$temp = sys_get_temp_dir() . '/opng-doctor-' . bin2hex(random_bytes(6));
mkdir($temp, 0700, true);
$migrations = $temp . '/migrations';
mkdir($migrations, 0700, true);
file_put_contents($migrations . '/0001_baseline.sql', "-- baseline\n");
file_put_contents($migrations . '/0002_foreign_keys.sql', "-- foreign keys\n");

$config = $temp . '/config.php';
file_put_contents($config, "<?php\n");
chmod($config, 0600);

$base = [
    'config_path' => $config,
    'config_password' => 'not-the-example-password',
    'database' => [
        'connected' => true,
        'server_info' => '8.0.36',
        'current_schema' => '0002_foreign_keys',
        'applied' => ['0001_baseline', '0002_foreign_keys'],
    ],
    'migrations_dir' => $migrations,
    'display_errors' => '0',
    'sapi' => 'fpm-fcgi',
    'dev_reset' => null,
    'install_creds' => false,
    'https' => true,
];

$results = opng_doctor_checks($base);
$summary = opng_doctor_summary($results);
expect_doctor(
    $summary[OPNG_DOCTOR_FAIL] === 0
        && $summary[OPNG_DOCTOR_WARN] === 0
        && $summary[OPNG_DOCTOR_SKIP] === 0
        && count($results) === 8,
    'a healthy production context passes every check',
    $failures
);

$by_id = [];
foreach ($results as $result) {
    $by_id[$result['id']] = $result['status'];
}
expect_doctor(
    ($by_id['config_file'] ?? null) === OPNG_DOCTOR_OK
        && ($by_id['database_password'] ?? null) === OPNG_DOCTOR_OK
        && ($by_id['database_connection'] ?? null) === OPNG_DOCTOR_OK
        && ($by_id['schema_version'] ?? null) === OPNG_DOCTOR_OK
        && ($by_id['display_errors'] ?? null) === OPNG_DOCTOR_OK
        && ($by_id['dev_reset'] ?? null) === OPNG_DOCTOR_OK
        && ($by_id['install_credentials'] ?? null) === OPNG_DOCTOR_OK
        && ($by_id['https'] ?? null) === OPNG_DOCTOR_OK,
    'the checklist reports every required deployment control',
    $failures
);

$cases = [
    'an empty database password fails' => [
        array_replace($base, ['config_password' => '']),
        'database_password',
        OPNG_DOCTOR_FAIL,
    ],
    'display_errors in the web SAPI fails' => [
        array_replace($base, ['display_errors' => '1']),
        'display_errors',
        OPNG_DOCTOR_FAIL,
    ],
    'the password-reset simulation fails when enabled' => [
        array_replace($base, ['dev_reset' => '1']),
        'dev_reset',
        OPNG_DOCTOR_FAIL,
    ],
    'plain HTTP fails' => [
        array_replace($base, ['https' => false]),
        'https',
        OPNG_DOCTOR_FAIL,
    ],
    'a pending migration fails' => [
        array_replace($base, [
            'database' => [
                'connected' => true,
                'server_info' => '8.0.36',
                'current_schema' => '0001_baseline',
                'applied' => ['0001_baseline'],
            ],
        ]),
        'schema_version',
        OPNG_DOCTOR_FAIL,
    ],
    'a missing migration record fails' => [
        array_replace($base, [
            'database' => [
                'connected' => true,
                'server_info' => '8.0.36',
                'current_schema' => '0002_foreign_keys',
                'applied' => ['0001_baseline'],
            ],
        ]),
        'schema_version',
        OPNG_DOCTOR_FAIL,
    ],
    'a failed database connection fails and skips schema inspection' => [
        array_replace($base, [
            'database' => [
                'connected' => false,
                'server_info' => '',
                'current_schema' => null,
                'applied' => [],
            ],
        ]),
        'database_connection',
        OPNG_DOCTOR_FAIL,
    ],
];

foreach ($cases as $message => [$context, $id, $expected]) {
    $statuses = [];
    foreach (opng_doctor_checks($context) as $result) {
        $statuses[$result['id']] = $result['status'];
    }
    expect_doctor(($statuses[$id] ?? null) === $expected, $message, $failures);
}

$failed_connection = array_replace($base, [
    'database' => [
        'connected' => false,
        'server_info' => '',
        'current_schema' => null,
        'applied' => [],
    ],
]);
$skip_statuses = [];
foreach (opng_doctor_checks($failed_connection) as $result) {
    $skip_statuses[$result['id']] = $result['status'];
}
expect_doctor(
    ($skip_statuses['schema_version'] ?? null) === OPNG_DOCTOR_SKIP,
    'schema inspection is skipped when the database is unreachable',
    $failures
);

chmod($config, 0666);
$writable_statuses = [];
foreach (opng_doctor_checks($base) as $result) {
    $writable_statuses[$result['id']] = $result['status'];
}
expect_doctor(
    ($writable_statuses['config_file'] ?? null) === OPNG_DOCTOR_FAIL,
    'a group/world-writable config.php fails the permission check',
    $failures
);
chmod($config, 0644);
$mode_statuses = [];
foreach (opng_doctor_checks($base) as $result) {
    $mode_statuses[$result['id']] = $result['status'];
}
expect_doctor(
    ($mode_statuses['config_file'] ?? null) === OPNG_DOCTOR_WARN,
    'a config.php readable by group/others is reported as a warning',
    $failures
);

$missing_config = array_replace($base, ['config_path' => $temp . '/missing.php']);
$missing_statuses = [];
foreach (opng_doctor_checks($missing_config) as $result) {
    $missing_statuses[$result['id']] = $result['status'];
}
expect_doctor(
    ($missing_statuses['config_file'] ?? null) === OPNG_DOCTOR_FAIL,
    'a missing config.php fails',
    $failures
);

$cli_display = array_replace($base, ['sapi' => 'cli', 'display_errors' => '1']);
$cli_statuses = [];
foreach (opng_doctor_checks($cli_display) as $result) {
    $cli_statuses[$result['id']] = $result['status'];
}
expect_doctor(
    ($cli_statuses['display_errors'] ?? null) === OPNG_DOCTOR_WARN,
    'an enabled CLI display_errors is a warning, not a web failure',
    $failures
);

$unknown_tls = array_replace($base, ['https' => null]);
$tls_statuses = [];
foreach (opng_doctor_checks($unknown_tls) as $result) {
    $tls_statuses[$result['id']] = $result['status'];
}
expect_doctor(
    ($tls_statuses['https'] ?? null) === OPNG_DOCTOR_WARN,
    'unknown TLS state is a warning with an actionable hint',
    $failures
);

$installer = array_replace($base, ['install_creds' => true]);
$installer_statuses = [];
foreach (opng_doctor_checks($installer) as $result) {
    $installer_statuses[$result['id']] = $result['status'];
}
expect_doctor(
    ($installer_statuses['install_credentials'] ?? null) === OPNG_DOCTOR_WARN,
    'leftover installer credentials are reported',
    $failures
);

$summary = opng_doctor_summary([
    ['status' => OPNG_DOCTOR_OK],
    ['status' => OPNG_DOCTOR_WARN],
    ['status' => OPNG_DOCTOR_FAIL],
    ['status' => OPNG_DOCTOR_SKIP],
]);
expect_doctor(
    $summary === [
        OPNG_DOCTOR_OK => 1,
        OPNG_DOCTOR_WARN => 1,
        OPNG_DOCTOR_FAIL => 1,
        OPNG_DOCTOR_SKIP => 1,
    ],
    'the summary counts every status',
    $failures
);

$tool = file_get_contents($root . '/tools/doctor.php');
expect_doctor(
    is_string($tool)
        && str_contains($tool, "PHP_SAPI !== 'cli'")
        && str_contains($tool, 'opng_doctor_checks(')
        && str_contains($tool, '--strict')
        && str_contains($tool, "\$options['json'] = true")
        && str_contains($tool, 'OPNG_PUBLIC_URL'),
    'tools/doctor.php is CLI-only and supports text, JSON and strict modes',
    $failures
);

$install = file_get_contents($root . '/src/install.php');
expect_doctor(
    is_string($install) && str_contains($install, 'tools/doctor.php'),
    'the installer points operators to the startup doctor',
    $failures
);

$readme = file_get_contents($root . '/README.md');
expect_doctor(
    is_string($readme)
        && str_contains($readme, 'php tools/doctor.php')
        && str_contains($readme, 'OPNG_PUBLIC_URL'),
    'README.md documents the doctor and OPNG_PUBLIC_URL',
    $failures
);

foreach (glob($migrations . '/*.sql') ?: [] as $file) {
    unlink($file);
}
rmdir($migrations);
@unlink($config);
rmdir($temp);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Doctor contracts passed: configuration, schema, security flags, TLS and CLI output.\n";

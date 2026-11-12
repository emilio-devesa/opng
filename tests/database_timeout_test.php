<?php

/**
 * Database timeout contracts (ROB-19).
 *
 * These checks are configuration-only: they do not open a connection, so they
 * run in the regular non-database suite.
 */

require __DIR__ . '/../src/includes/db.php';

$failures = [];

function timeout_expect(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
    }
}

putenv('OPNG_DB_CONNECT_TIMEOUT');
timeout_expect(
    db_connect_timeout_seconds() === 5,
    'the default connection timeout must be five seconds',
    $failures
);

putenv('OPNG_DB_CONNECT_TIMEOUT=17');
timeout_expect(
    db_connect_timeout_seconds() === 17,
    'OPNG_DB_CONNECT_TIMEOUT must override the connection timeout',
    $failures
);

foreach (['0', '-1', '61', 'not-a-number'] as $invalid) {
    putenv('OPNG_DB_CONNECT_TIMEOUT=' . $invalid);
    timeout_expect(
        db_connect_timeout_seconds() === 5,
        'an invalid OPNG_DB_CONNECT_TIMEOUT must fall back to five seconds',
        $failures
    );
}

putenv('OPNG_DB_READ_TIMEOUT');
timeout_expect(
    db_read_timeout_seconds() === null,
    'the read timeout must be disabled by default',
    $failures
);

putenv('OPNG_DB_READ_TIMEOUT=45');
timeout_expect(
    db_read_timeout_seconds() === 45,
    'OPNG_DB_READ_TIMEOUT must override the read timeout',
    $failures
);

foreach (['0', '-1', '3601', 'not-a-number'] as $invalid) {
    putenv('OPNG_DB_READ_TIMEOUT=' . $invalid);
    timeout_expect(
        db_read_timeout_seconds() === null,
        'an invalid OPNG_DB_READ_TIMEOUT must disable the read timeout',
        $failures
    );
}

$db_source = file_get_contents(__DIR__ . '/../src/includes/db.php');
timeout_expect(
    is_string($db_source)
        && str_contains($db_source, 'MYSQLI_OPT_CONNECT_TIMEOUT')
        && str_contains($db_source, 'MYSQLI_OPT_READ_TIMEOUT'),
    'db_connect() must apply both mysqli timeout options',
    $failures
);

$config_example = file_get_contents(__DIR__ . '/../src/config.example.php');
timeout_expect(
    is_string($config_example)
        && str_contains($config_example, 'OPNG_DB_CONNECT_TIMEOUT')
        && str_contains($config_example, 'OPNG_DB_READ_TIMEOUT'),
    'config.example.php must document both timeout variables',
    $failures
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Database timeout contracts passed: connect and optional read timeouts are configurable.\n";

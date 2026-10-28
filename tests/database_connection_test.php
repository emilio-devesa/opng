<?php

if (getenv('OPNG_TEST_ALLOW_CONNECTION_PROBE') !== '1') {
    throw new RuntimeException('Run this connection probe only in the isolated CI environment.');
}

require __DIR__ . '/../src/includes/db.php';

putenv('OPNG_DB_HOST=127.0.0.1');
putenv('OPNG_DB_USER=opng_connection_probe_' . bin2hex(random_bytes(6)));
putenv('OPNG_DB_PASS=' . bin2hex(random_bytes(16)));
putenv('OPNG_DB_NAME=opng_connection_probe');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db = db_connect();
if ($db !== null) {
    $db->close();
    fwrite(STDERR, "FAIL: the connection probe unexpectedly connected\n");
    exit(1);
}

echo "PASS: database connection failures are returned as a controlled null result\n";

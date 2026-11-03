<?php

$root = dirname(__DIR__);
$failures = [];

function expect_robust(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

$backup = file_get_contents($root . '/src/admin/backup.php');
$rewind = is_string($backup) ? strpos($backup, 'fseek($temporary_stream, 0)') : false;
$headers = is_string($backup) ? strpos($backup, "header('Content-Type: application/sql") : false;
expect_robust(
    $rewind !== false && $headers !== false && $rewind < $headers,
    'the backup stream is rewound before any response header is sent',
    $failures
);
expect_robust(
    is_string($backup)
        && str_contains($backup, "error_log('OPNG backup.php: backup temporary stream could not be rewound.')")
        && !str_contains($backup, "throw new RuntimeException('Backup temporary stream could not be rewound.')")
        && str_contains($backup, "opng_error_redirect('error_database_operation', '../error.php')"),
    'a failed rewind is logged and redirected instead of throwing after the headers',
    $failures
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Robustness contracts passed: backup rewinds before headers and fails cleanly.\n";

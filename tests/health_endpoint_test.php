<?php

$root = dirname(__DIR__);
$source = file_get_contents($root . '/src/healthz.php');
$failures = [];

function expect_health(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

expect_health(is_string($source), 'healthz.php exists and is readable', $failures);
if (is_string($source)) {
    expect_health(
        str_contains($source, "Cache-Control: no-store"),
        'health responses are never cached',
        $failures
    );
    expect_health(
        str_contains($source, "'status' => 'ok'")
            && str_contains($source, "'schema' => \$schema"),
        'a healthy response reports only status and schema version',
        $failures
    );
    expect_health(
        str_contains($source, 'function healthz_respond(int $status, array $payload)')
            && str_contains($source, 'http_response_code($status)')
            && str_contains($source, "healthz_respond(503, ['status' => 'error'])")
            && str_contains($source, "['status' => 'error']"),
        'database or schema failures return an opaque 503',
        $failures
    );
    expect_health(
        str_contains($source, 'schema_migrations')
            && str_contains($source, 'storage_counters')
            && str_contains($source, 'disk_free_space'),
        'health checks the schema version and storage headroom',
        $failures
    );
    expect_health(
        !str_contains($source, 'mysqli_get_server_info')
            && !str_contains($source, '$mysql_password')
            && !str_contains($source, 'display_errors'),
        'health output does not leak server or credential details',
        $failures
    );
    expect_health(
        str_contains($source, "require(__DIR__ . '/includes/db.php')")
            && str_contains($source, "require(__DIR__ . '/includes/user_quota.php')")
            && !str_contains($source, 'admin_guard'),
        'the endpoint is unauthenticated and independent from admin guards',
        $failures
    );
}

$nginx = file_get_contents($root . '/deploy/nginx.example.conf');
expect_health(
    is_string($nginx) && str_contains($nginx, 'healthz.php'),
    'the deployment example documents the health endpoint',
    $failures
);

// The Compose file is optional; only validate it when present.
$compose_path = $root . '/docker-compose.yml';
if (is_file($compose_path)) {
    $compose = file_get_contents($compose_path);
    expect_health(
        is_string($compose) && str_contains($compose, 'healthz.php'),
        'the container example uses the health endpoint',
        $failures
    );
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Health endpoint contracts passed: no-cache opaque JSON, schema/storage checks, and deployment usage.\n";

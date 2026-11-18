<?php

require_once __DIR__ . '/bootstrap.php';
// healthz.php
// Cheap, unauthenticated health check for the container and monitoring (ROB-06).
//
// Returns 200 {"status":"ok"} when the database, schema version and storage
// headroom are usable, or 503 {"status":"error"} without leaking credentials or
// server internals. The schema version is only included when the request sends
// the OPNG_HEALTHZ_DETAIL_TOKEN as an Authorization: Bearer token (SEC-26).
require(__DIR__ . '/includes/user_quota.php');
require(__DIR__ . '/includes/healthz.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('Referrer-Policy: no-referrer');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['status' => 'error']);
    exit;
}

/**
 * @param array<string, mixed> $payload
 */
function healthz_respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$db = db_connect();
if (!$db) {
    healthz_respond(503, ['status' => 'error']);
}

try {
    $schema_result = $db->query(
        'SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1'
    );
    $schema_row = $schema_result ? $schema_result->fetch_assoc() : null;
    if ($schema_result instanceof mysqli_result) {
        $schema_result->free();
    }
    $schema = is_array($schema_row) && is_string($schema_row['version'] ?? null)
        ? $schema_row['version']
        : null;

    $storage_result = $db->query(
        "SELECT used_bytes FROM storage_counters WHERE counter_key = 'global'"
    );
    $storage_row = $storage_result ? $storage_result->fetch_assoc() : null;
    if ($storage_result instanceof mysqli_result) {
        $storage_result->free();
    }
    $used_bytes = is_array($storage_row) ? (int) ($storage_row['used_bytes'] ?? -1) : -1;

    $disk_free = @disk_free_space(dirname(__DIR__));
    $storage_ok = $used_bytes >= 0
        && $used_bytes < global_storage_quota_bytes()
        && ($disk_free === false || $disk_free > 16 * 1024 * 1024);
} catch (mysqli_sql_exception $exception) {
    error_log('OPNG healthz.php: ' . $exception->getMessage());
    $db->close();
    healthz_respond(503, ['status' => 'error']);
}

$db->close();

if ($schema === null || !$storage_ok) {
    healthz_respond(503, ['status' => 'error']);
}

$detail_token = getenv('OPNG_HEALTHZ_DETAIL_TOKEN');
$payload = ['status' => 'ok'];
if (
    healthz_detail_authorized(
        is_string($detail_token) ? $detail_token : null,
        healthz_authorization_header($_SERVER)
    )
) {
    $payload['schema'] = $schema;
}

healthz_respond(200, $payload);

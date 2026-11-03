<?php
// healthz.php
// Cheap, unauthenticated health check for the container and monitoring (ROB-06).
//
// Returns 200 {"status":"ok","schema":"..."} when the database, schema version
// and storage headroom are usable, or 503 {"status":"error"} without leaking
// credentials or server internals.

require(__DIR__ . '/includes/db.php');
require(__DIR__ . '/includes/user_quota.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['status' => 'error']);
    exit;
}

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

healthz_respond(200, ['status' => 'ok', 'schema' => $schema]);

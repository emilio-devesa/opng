<?php

declare(strict_types=1);

// includes/external_backup.php
// Off-host backup targets with checksums, retention and restore verification
// (10.7).
//
// Targets:
//   local  A mounted directory. Files can be encrypted with AES-256-GCM using
//          OPNG_BACKUP_KEY (base64, 32 bytes).
//   s3     Any S3-compatible endpoint. Uploads use native SigV4 signing; no SDK
//          or Composer package is required.
//
// Local backups have a SHA-256 sidecar. Only an explicitly configured
// disposable verifier server may import SQL; runtime needs no extra grants.

require_once __DIR__ . '/sql_backup.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/logging.php';

const OPNG_EXTERNAL_BACKUP_MAGIC = "OPNGBK1\n";

/**
 * @return array{
 *   target: string,
 *   directory: string,
 *   retention_days: int,
 *   key: string,
 *   s3_endpoint: string,
 *   s3_bucket: string,
 *   s3_region: string,
 *   s3_access_key: string,
 *   s3_secret_key: string,
 *   s3_prefix: string,
 *   s3_path_style: bool,
 *   timeout: int
 * }
 */
function external_backup_config(): array
{
    $target = strtolower(trim((string) (getenv('OPNG_BACKUP_TARGET') ?: 'none')));
    if (!in_array($target, ['none', 'local', 's3'], true)) {
        $target = 'none';
    }
    $retention = filter_var(getenv('OPNG_BACKUP_RETENTION_DAYS'), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 3650],
    ]);
    $timeout = filter_var(getenv('OPNG_BACKUP_TIMEOUT'), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 600],
    ]);

    return [
        'target' => $target,
        'directory' => trim((string) (getenv('OPNG_BACKUP_DIR') ?: '')),
        'retention_days' => $retention === false ? 30 : (int) $retention,
        'key' => trim((string) (getenv('OPNG_BACKUP_KEY') ?: '')),
        's3_endpoint' => trim((string) (getenv('OPNG_BACKUP_S3_ENDPOINT') ?: '')),
        's3_bucket' => trim((string) (getenv('OPNG_BACKUP_S3_BUCKET') ?: '')),
        's3_region' => trim((string) (getenv('OPNG_BACKUP_S3_REGION') ?: 'us-east-1')),
        's3_access_key' => (string) (getenv('OPNG_BACKUP_S3_ACCESS_KEY') ?: ''),
        's3_secret_key' => (string) (getenv('OPNG_BACKUP_S3_SECRET_KEY') ?: ''),
        's3_prefix' => trim((string) (getenv('OPNG_BACKUP_S3_PREFIX') ?: 'opng')),
        's3_path_style' => getenv('OPNG_BACKUP_S3_PATH_STYLE') !== '0',
        'timeout' => $timeout === false ? 30 : (int) $timeout,
    ];
}

function external_backup_enabled(): bool
{
    return external_backup_config()['target'] !== 'none';
}

/**
 * @return list<string>
 */
/**
 * @param array<string, mixed>|null $config
 * @return list<string>
 */
function external_backup_validate_config(?array $config = null): array
{
    $config = $config ?? external_backup_config();
    $errors = [];

    if (!in_array($config['target'], ['none', 'local', 's3'], true)) {
        $errors[] = 'OPNG_BACKUP_TARGET must be none, local or s3.';
    }
    if ($config['key'] !== '') {
        $decoded = base64_decode($config['key'], true);
        if (!is_string($decoded) || strlen($decoded) !== 32) {
            $errors[] = 'OPNG_BACKUP_KEY must be 32 bytes encoded as base64.';
        }
    }

    if ($config['target'] === 'local') {
        if ($config['directory'] === '' || !str_starts_with($config['directory'], '/')) {
            $errors[] = 'OPNG_BACKUP_DIR must be an absolute path for the local target.';
        } elseif (!is_dir($config['directory'])) {
            $errors[] = 'OPNG_BACKUP_DIR does not exist.';
        } elseif (!is_writable($config['directory'])) {
            $errors[] = 'OPNG_BACKUP_DIR is not writable by the application account.';
        }
    }

    if ($config['target'] === 's3') {
        foreach (
            [
            's3_endpoint' => 'OPNG_BACKUP_S3_ENDPOINT',
            's3_bucket' => 'OPNG_BACKUP_S3_BUCKET',
            's3_region' => 'OPNG_BACKUP_S3_REGION',
            's3_access_key' => 'OPNG_BACKUP_S3_ACCESS_KEY',
            's3_secret_key' => 'OPNG_BACKUP_S3_SECRET_KEY',
            ] as $field => $name
        ) {
            if ($config[$field] === '') {
                $errors[] = "{$name} is required for the s3 target.";
            }
        }
        if ($config['s3_endpoint'] !== '' && filter_var($config['s3_endpoint'], FILTER_VALIDATE_URL) === false) {
            $errors[] = 'OPNG_BACKUP_S3_ENDPOINT must be an HTTP(S) URL.';
        }
        if (preg_match('#[\r\n]#', $config['s3_bucket'] . $config['s3_prefix']) === 1) {
            $errors[] = 'S3 bucket and prefix must not contain line breaks.';
        }
    }

    return $errors;
}

function external_backup_key(): ?string
{
    $config = external_backup_config();
    if ($config['key'] === '') {
        return null;
    }
    $decoded = base64_decode($config['key'], true);

    return is_string($decoded) && strlen($decoded) === 32 ? $decoded : null;
}

function external_backup_encrypt(string $plain): string
{
    $key = external_backup_key();
    if ($key === null) {
        throw new RuntimeException('OPNG_BACKUP_KEY is not configured.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($cipher) || strlen($tag) !== 16) {
        throw new RuntimeException('Backup encryption failed.');
    }

    return OPNG_EXTERNAL_BACKUP_MAGIC . base64_encode($iv . $tag . $cipher);
}

function external_backup_decrypt(string $payload): string
{
    if (!str_starts_with($payload, OPNG_EXTERNAL_BACKUP_MAGIC)) {
        return $payload;
    }
    $key = external_backup_key();
    if ($key === null) {
        throw new RuntimeException('OPNG_BACKUP_KEY is required to read this backup.');
    }
    $raw = base64_decode(substr($payload, strlen(OPNG_EXTERNAL_BACKUP_MAGIC)), true);
    if (!is_string($raw) || strlen($raw) < 29) {
        throw new RuntimeException('Encrypted backup is truncated.');
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($plain)) {
        throw new RuntimeException('Encrypted backup could not be decrypted.');
    }

    return $plain;
}

/**
 * Run one external backup and return its metadata.
 *
 * @return array{target: string, name: string, path: string|null, url: string|null, checksum: string, bytes: int}
 */
function external_backup_run(mysqli $db): array
{
    $config = external_backup_config();
    $errors = external_backup_validate_config($config);
    if ($errors !== []) {
        throw new RuntimeException(implode(' ', $errors));
    }
    if ($config['target'] === 'none') {
        throw new RuntimeException('No external backup target is configured.');
    }

    $stream = fopen('php://temp/maxmemory:4194304', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Backup temporary stream is unavailable.');
    }
    try {
        sql_backup_dump($db, $stream, sql_backup_max_seconds(), sql_backup_max_bytes());
        rewind($stream);
        $dump = stream_get_contents($stream);
    } finally {
        fclose($stream);
    }
    if (!is_string($dump) || $dump === '') {
        throw new RuntimeException('Backup dump is empty.');
    }

    $name = 'opng-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $checksum = hash('sha256', $dump);
    $payload = $dump;
    if ($config['target'] === 'local' && $config['key'] !== '') {
        $payload = external_backup_encrypt($dump);
        $name .= '.enc';
    } else {
        $name .= '.sql';
    }

    if ($config['target'] === 'local') {
        $path = rtrim($config['directory'], '/') . '/' . $name;
        $temporary = $path . '.tmp';
        $written = @file_put_contents($temporary, $payload, LOCK_EX);
        if (!is_int($written) || $written !== strlen($payload)) {
            @unlink($temporary);
            throw new RuntimeException('Backup file could not be written.');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Backup file could not be finalized.');
        }
        $checksumLine = $checksum . '  ' . $name . "\n";
        if (@file_put_contents($path . '.sha256', $checksumLine, LOCK_EX) === false) {
            throw new RuntimeException('Backup checksum could not be written.');
        }
        external_backup_prune_local($config);
        opng_log('info', 'external_backup_completed', [
            'target' => 'local',
            'name' => $name,
            'bytes' => strlen($payload),
            'checksum' => $checksum,
        ]);

        return [
            'target' => 'local',
            'name' => $name,
            'path' => $path,
            'url' => null,
            'checksum' => $checksum,
            'bytes' => strlen($payload),
        ];
    }

    $objectKey = trim($config['s3_prefix'], '/');
    $objectKey = $objectKey === '' ? $name : $objectKey . '/' . $name;
    $url = external_backup_s3_put($payload, $objectKey, $config);
    opng_log('info', 'external_backup_completed', [
        'target' => 's3',
        'name' => $name,
        'bytes' => strlen($payload),
        'checksum' => $checksum,
    ]);

    return [
        'target' => 's3',
        'name' => $name,
        'path' => null,
        'url' => $url,
        'checksum' => $checksum,
        'bytes' => strlen($payload),
    ];
}

/**
 * Remove local backups older than the retention window.
 */
/**
 * @param array<string, mixed> $config
 */
function external_backup_prune_local(array $config): int
{
    $files = glob(rtrim($config['directory'], '/') . '/opng-*');
    if ($files === false) {
        return 0;
    }
    $threshold = time() - ($config['retention_days'] * 86400);
    $removed = 0;
    foreach ($files as $file) {
        if (str_ends_with($file, '.sha256') || str_ends_with($file, '.tmp')) {
            continue;
        }
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $threshold && @unlink($file)) {
            @unlink($file . '.sha256');
            $removed++;
        }
    }

    return $removed;
}

/**
 * Upload one object with AWS Signature Version 4.
 */
/**
 * @param array<string, mixed> $config
 */
function external_backup_s3_put(string $body, string $objectKey, array $config): string
{
    $endpoint = rtrim($config['s3_endpoint'], '/');
    $parsed = parse_url($endpoint);
    if (!is_array($parsed) || !is_string($parsed['host'] ?? null)) {
        throw new RuntimeException('S3 endpoint is invalid.');
    }
    $scheme = strtolower((string) ($parsed['scheme'] ?? 'https'));
    $host = $parsed['host'];
    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
    $basePath = rtrim((string) ($parsed['path'] ?? ''), '/');
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', $objectKey)));

    if ($config['s3_path_style']) {
        $canonicalUri = $basePath . '/' . rawurlencode($config['s3_bucket']) . '/' . $encodedKey;
        $hostHeader = $host . $port;
    } else {
        $canonicalUri = $basePath . '/' . $encodedKey;
        $hostHeader = $config['s3_bucket'] . '.' . $host . $port;
    }
    $now = time();
    $amzDate = gmdate('Ymd\THis\Z', $now);
    $dateStamp = gmdate('Ymd', $now);
    $payloadHash = hash('sha256', $body);
    $headers = [
        'host' => $hostHeader,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $amzDate,
    ];
    ksort($headers);
    $canonicalHeaders = '';
    foreach ($headers as $name => $value) {
        $canonicalHeaders .= $name . ':' . trim($value) . "\n";
    }
    $signedHeaders = implode(';', array_keys($headers));
    $canonicalRequest = "PUT\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
    $scope = $dateStamp . '/' . $config['s3_region'] . '/s3/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);

    $signingKey = hash_hmac('sha256', $dateStamp, 'AWS4' . $config['s3_secret_key'], true);
    $signingKey = hash_hmac('sha256', $config['s3_region'], $signingKey, true);
    $signingKey = hash_hmac('sha256', 's3', $signingKey, true);
    $signingKey = hash_hmac('sha256', 'aws4_request', $signingKey, true);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);

    $authorization = 'AWS4-HMAC-SHA256 Credential=' . $config['s3_access_key'] . '/' . $scope
        . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

    $headerLines = [
        'Authorization: ' . $authorization,
        'Content-Type: application/octet-stream',
        'x-amz-content-sha256: ' . $payloadHash,
        'x-amz-date: ' . $amzDate,
        'Host: ' . $hostHeader,
        'Content-Length: ' . strlen($body),
    ];
    $context = stream_context_create([
        'http' => [
            'method' => 'PUT',
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'timeout' => $config['timeout'],
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $url = $scheme . '://' . $hostHeader . $canonicalUri;
    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (preg_match('/\AHTTP\/\S+\s+(\d{3})/', $statusLine, $match) !== 1 || (int) $match[1] >= 300) {
        throw new RuntimeException('S3 upload failed: ' . $statusLine . ' ' . (string) $response);
    }

    return $url;
}

/**
 * Verify a local backup file: checksum sidecar, optional decryption, then a
 * disposable-schema import.
 *
 * @return array{tables: int, rows: int, encrypted: bool}
 */
function external_backup_verify_file(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Backup file is not readable.');
    }
    $payload = file_get_contents($path);
    if (!is_string($payload)) {
        throw new RuntimeException('Backup file could not be read.');
    }

    $checksumFile = $path . '.sha256';
    $encrypted = str_ends_with($path, '.enc');
    if ($encrypted !== str_starts_with($payload, OPNG_EXTERNAL_BACKUP_MAGIC)) {
        throw new RuntimeException('Backup encryption marker does not match the file format.');
    }
    $plain = $encrypted ? external_backup_decrypt($payload) : $payload;
    $line = is_file($checksumFile) ? file_get_contents($checksumFile) : false;
    if (
        !is_string($line)
        || !preg_match('/\A([a-f0-9]{64})  [^\r\n]+\r?\n?\z/', $line, $match)
        || !hash_equals($match[1], hash('sha256', $plain))
    ) {
        throw new RuntimeException('Backup checksum is missing, invalid or does not match.');
    }

    $inventory = external_backup_snapshot_inventory($plain);
    $host = getenv('OPNG_VERIFY_DB_HOST');
    $user = getenv('OPNG_VERIFY_DB_USER');
    $password = getenv('OPNG_VERIFY_DB_PASS');
    $port = filter_var(getenv('OPNG_VERIFY_DB_PORT') ?: '3306', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    if (!$host || !$user || $password === false || $port === false) {
        throw new RuntimeException('Configure OPNG_VERIFY_DB_* for an isolated restore server.');
    }
    $db = new mysqli($host, $user, $password, null, $port);
    try {
        $db->set_charset('utf8mb4');
        $result = external_backup_verify_sql($db, $plain, $inventory);
    } finally {
        $db->close();
    }
    $result['encrypted'] = $encrypted;

    return $result;
}

/**
 * @return array<string|int, int>
 */
function external_backup_snapshot_inventory(string $sql): array
{
    if (!preg_match('/^-- OPNG-SNAPSHOT-v1 (\{[^\r\n]*\})\r?\n?\z/m', $sql, $match)) {
        throw new RuntimeException('Snapshot inventory missing; completeness cannot be verified.');
    }
    $tables = json_decode($match[1], true);
    if (!is_array($tables) || $tables === []) {
        throw new RuntimeException('Invalid snapshot inventory.');
    }
    foreach ($tables as $rows) {
        if (!is_int($rows) || $rows < 0) {
            throw new RuntimeException('Invalid row count in snapshot inventory.');
        }
    }
    ksort($tables, SORT_STRING);
    return $tables;
}

/**
 * Import on a dedicated verifier connection and compare snapshot counts.
 *
 * @param array<string|int, int> $inventory
 * @return array{tables: int, rows: int}
 */
function external_backup_verify_sql(mysqli $db, string $sql, array $inventory): array
{
    $temporaryDatabase = 'opng_verify_' . bin2hex(random_bytes(6));
    if (!$db->query("CREATE DATABASE `{$temporaryDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        throw new RuntimeException('Restore verification requires CREATE DATABASE privileges.');
    }

    try {
        if (!$db->select_db($temporaryDatabase)) {
            throw new RuntimeException('The disposable verification database could not be selected.');
        }
        foreach (schema_split_sql($sql) as $statement) {
            if (!$db->query($statement)) {
                throw new RuntimeException('Restore verification import failed: ' . $db->error);
            }
        }

        $tablesResult = $db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        );
        $tables = [];
        while ($row = $tablesResult->fetch_assoc()) {
            if (is_string($row['TABLE_NAME'] ?? null)) {
                $tables[] = $row['TABLE_NAME'];
            }
        }
        $tablesResult->free();
        sort($tables, SORT_STRING);

        $actual = [];
        foreach ($tables as $table) {
            $quoted = '`' . str_replace('`', '``', $table) . '`';
            $countResult = $db->query("SELECT COUNT(*) AS total FROM {$quoted}");
            $countRow = $countResult ? $countResult->fetch_assoc() : null;
            if ($countResult instanceof mysqli_result) {
                $countResult->free();
            }
            if (!isset($countRow['total'])) {
                throw new RuntimeException('Restored table count unavailable.');
            }
            $actual[$table] = (int) $countRow['total'];
        }

        if ($actual === [] || $actual !== $inventory) {
            throw new RuntimeException('Restored tables or row counts differ from the snapshot inventory.');
        }
        return ['tables' => count($tables), 'rows' => array_sum($actual)];
    } finally {
        $db->query("DROP DATABASE IF EXISTS `{$temporaryDatabase}`");
    }
}

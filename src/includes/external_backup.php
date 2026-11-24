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

const OPNG_EXTERNAL_BACKUP_MAGIC_V1 = "OPNGBK1\n";
// OPNGBK2 is a chunked, authenticated format. Each frame carries its own
// plaintext length, IV and GCM tag, so encryption and decryption never need the
// whole dump in memory. OPNGBK1 backups remain readable.
const OPNG_EXTERNAL_BACKUP_MAGIC = "OPNGBK2\n";
const OPNG_EXTERNAL_BACKUP_CHUNK_BYTES = 1048576;
const OPNG_EXTERNAL_BACKUP_MAX_CHUNK_BYTES = 16777216;

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

/**
 * @param resource $stream
 */
function external_backup_write_all($stream, string $contents): void
{
    $offset = 0;
    $length = strlen($contents);
    while ($offset < $length) {
        $written = fwrite($stream, substr($contents, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Backup stream could not be written.');
        }
        $offset += $written;
    }
}

/**
 * Read exactly $length bytes, or return null at a clean EOF when allowed.
 *
 * @param resource $stream
 */
function external_backup_read_exact($stream, int $length, bool $allowEof = false): ?string
{
    $buffer = '';
    while (strlen($buffer) < $length) {
        $chunk = fread($stream, $length - strlen($buffer));
        if ($chunk === false) {
            throw new RuntimeException('Backup stream could not be read.');
        }
        if ($chunk === '') {
            if (feof($stream)) {
                if ($allowEof && $buffer === '') {
                    return null;
                }
                throw new RuntimeException('Encrypted backup is truncated.');
            }
            continue;
        }
        $buffer .= $chunk;
    }

    return $buffer;
}

/**
 * Hash a seekable plaintext stream and return its size.
 *
 * @param resource $stream
 * @return array{checksum: string, bytes: int}
 */
function external_backup_stream_checksum($stream): array
{
    if (fseek($stream, 0) !== 0) {
        throw new RuntimeException('Backup stream is not seekable.');
    }
    $context = hash_init('sha256');
    $bytes = 0;
    while (!feof($stream)) {
        $chunk = fread($stream, OPNG_EXTERNAL_BACKUP_CHUNK_BYTES);
        if ($chunk === false) {
            throw new RuntimeException('Backup stream could not be read.');
        }
        if ($chunk === '') {
            if (feof($stream)) {
                break;
            }
            continue;
        }
        hash_update($context, $chunk);
        $bytes += strlen($chunk);
    }
    if (fseek($stream, 0) !== 0) {
        throw new RuntimeException('Backup stream is not seekable.');
    }

    return ['checksum' => hash_final($context), 'bytes' => $bytes];
}

/**
 * @param resource $input
 * @param resource $output
 */
function external_backup_stream_copy($input, $output): int
{
    if (fseek($input, 0) !== 0) {
        throw new RuntimeException('Backup stream is not seekable.');
    }
    $bytes = 0;
    while (!feof($input)) {
        $chunk = fread($input, OPNG_EXTERNAL_BACKUP_CHUNK_BYTES);
        if ($chunk === false) {
            throw new RuntimeException('Backup stream could not be read.');
        }
        if ($chunk === '') {
            if (feof($input)) {
                break;
            }
            continue;
        }
        external_backup_write_all($output, $chunk);
        $bytes += strlen($chunk);
    }
    if (fseek($input, 0) !== 0) {
        throw new RuntimeException('Backup stream is not seekable.');
    }

    return $bytes;
}

/**
 * Encrypt a plaintext stream with chunked AES-256-GCM.
 *
 * @param resource $input
 * @param resource $output
 */
function external_backup_encrypt_stream($input, $output): int
{
    $key = external_backup_key();
    if ($key === null) {
        throw new RuntimeException('OPNG_BACKUP_KEY is not configured.');
    }
    if (fseek($input, 0) !== 0) {
        throw new RuntimeException('Backup stream is not seekable.');
    }
    external_backup_write_all($output, OPNG_EXTERNAL_BACKUP_MAGIC);
    $index = 0;
    $total = 0;
    while (!feof($input)) {
        $chunk = fread($input, OPNG_EXTERNAL_BACKUP_CHUNK_BYTES);
        if ($chunk === false) {
            throw new RuntimeException('Backup stream could not be read.');
        }
        if ($chunk === '') {
            if (feof($input)) {
                break;
            }
            continue;
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $chunk,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            pack('N', $index),
            16
        );
        if (!is_string($cipher) || strlen($tag) !== 16) {
            throw new RuntimeException('Backup encryption failed.');
        }
        external_backup_write_all(
            $output,
            pack('N', strlen($chunk)) . $iv . $tag . $cipher
        );
        $total += strlen($chunk);
        $index++;
    }
    external_backup_write_all($output, pack('N', 0));
    if (fseek($input, 0) !== 0) {
        throw new RuntimeException('Backup stream is not seekable.');
    }

    return $total;
}

/**
 * @param resource $input
 * @param resource $output
 */
function external_backup_decrypt_stream($input, $output): int
{
    $key = external_backup_key();
    if ($key === null) {
        throw new RuntimeException('OPNG_BACKUP_KEY is required to read this backup.');
    }
    if (fseek($input, 0) !== 0) {
        throw new RuntimeException('Backup stream is not seekable.');
    }
    $header = external_backup_read_exact($input, strlen(OPNG_EXTERNAL_BACKUP_MAGIC));
    if ($header === OPNG_EXTERNAL_BACKUP_MAGIC_V1) {
        $payload = stream_get_contents($input);
        if (!is_string($payload)) {
            throw new RuntimeException('Backup stream could not be read.');
        }
        $plain = external_backup_decrypt_v1_payload($payload, $key);
        external_backup_write_all($output, $plain);
        if (fseek($input, 0) !== 0) {
            throw new RuntimeException('Backup stream is not seekable.');
        }

        return strlen($plain);
    }
    if ($header !== OPNG_EXTERNAL_BACKUP_MAGIC) {
        throw new RuntimeException('Encrypted backup marker is invalid.');
    }

    $index = 0;
    $total = 0;
    while (true) {
        $lengthBytes = external_backup_read_exact($input, 4, true);
        if ($lengthBytes === null) {
            throw new RuntimeException('Encrypted backup is truncated.');
        }
        $unpacked = unpack('Nlength', $lengthBytes);
        if (!is_array($unpacked) || !isset($unpacked['length'])) {
            throw new RuntimeException('Encrypted backup chunk header is invalid.');
        }
        $length = (int) $unpacked['length'];
        if ($length === 0) {
            $extra = fread($input, 1);
            if ($extra === false || $extra !== '') {
                throw new RuntimeException('Encrypted backup has trailing data.');
            }
            break;
        }
        if ($length < 1 || $length > OPNG_EXTERNAL_BACKUP_MAX_CHUNK_BYTES) {
            throw new RuntimeException('Encrypted backup chunk size is invalid.');
        }
        $iv = external_backup_read_exact($input, 12);
        $tag = external_backup_read_exact($input, 16);
        $cipher = external_backup_read_exact($input, $length);
        if (!is_string($iv) || !is_string($tag) || !is_string($cipher)) {
            throw new RuntimeException('Encrypted backup is truncated.');
        }
        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            pack('N', $index)
        );
        if (!is_string($plain)) {
            throw new RuntimeException('Encrypted backup could not be decrypted.');
        }
        external_backup_write_all($output, $plain);
        $total += strlen($plain);
        $index++;
    }
    if (fseek($input, 0) !== 0) {
        throw new RuntimeException('Backup stream is not seekable.');
    }

    return $total;
}

function external_backup_decrypt_v1_payload(string $payload, string $key): string
{
    $raw = base64_decode($payload, true);
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

function external_backup_encrypt(string $plain): string
{
    $input = fopen('php://temp', 'w+b');
    $output = fopen('php://temp/maxmemory:4194304', 'w+b');
    if ($input === false || $output === false) {
        throw new RuntimeException('Backup temporary stream is unavailable.');
    }
    try {
        external_backup_write_all($input, $plain);
        external_backup_encrypt_stream($input, $output);
        if (fseek($output, 0) !== 0) {
            throw new RuntimeException('Backup stream is not seekable.');
        }
        $payload = stream_get_contents($output);
        if (!is_string($payload)) {
            throw new RuntimeException('Backup stream could not be read.');
        }

        return $payload;
    } finally {
        fclose($input);
        fclose($output);
    }
}

function external_backup_decrypt(string $payload): string
{
    if (
        !str_starts_with($payload, OPNG_EXTERNAL_BACKUP_MAGIC)
        && !str_starts_with($payload, OPNG_EXTERNAL_BACKUP_MAGIC_V1)
    ) {
        return $payload;
    }
    $input = fopen('php://temp', 'w+b');
    $output = fopen('php://temp/maxmemory:4194304', 'w+b');
    if ($input === false || $output === false) {
        throw new RuntimeException('Backup temporary stream is unavailable.');
    }
    try {
        external_backup_write_all($input, $payload);
        external_backup_decrypt_stream($input, $output);
        if (fseek($output, 0) !== 0) {
            throw new RuntimeException('Backup stream is not seekable.');
        }
        $plain = stream_get_contents($output);
        if (!is_string($plain)) {
            throw new RuntimeException('Backup stream could not be read.');
        }

        return $plain;
    } finally {
        fclose($input);
        fclose($output);
    }
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
        $hashed = external_backup_stream_checksum($stream);
        if ($hashed['bytes'] < 1) {
            throw new RuntimeException('Backup dump is empty.');
        }
        $checksum = $hashed['checksum'];
        $dumpBytes = $hashed['bytes'];
        $name = 'opng-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));

        if ($config['target'] === 'local') {
            $encrypted = $config['key'] !== '';
            $name .= $encrypted ? '.enc' : '.sql';
            $path = rtrim($config['directory'], '/') . '/' . $name;
            $temporary = tempnam($config['directory'], '.opng-backup-');
            if ($temporary === false) {
                throw new RuntimeException('Backup temporary file could not be created.');
            }
            @chmod($temporary, 0600);
            $output = fopen($temporary, 'wb');
            if ($output === false) {
                @unlink($temporary);
                throw new RuntimeException('Backup file could not be written.');
            }
            try {
                $payloadBytes = $encrypted
                    ? external_backup_encrypt_stream($stream, $output)
                    : external_backup_stream_copy($stream, $output);
                if (!fflush($output)) {
                    throw new RuntimeException('Backup file could not be flushed.');
                }
            } catch (Throwable $exception) {
                fclose($output);
                @unlink($temporary);
                throw $exception;
            }
            fclose($output);
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
                'bytes' => $payloadBytes,
                'checksum' => $checksum,
            ]);

            return [
                'target' => 'local',
                'name' => $name,
                'path' => $path,
                'url' => null,
                'checksum' => $checksum,
                'bytes' => $payloadBytes,
            ];
        }

        $name .= '.sql';
        $objectKey = trim($config['s3_prefix'], '/');
        $objectKey = $objectKey === '' ? $name : $objectKey . '/' . $name;
        $url = external_backup_s3_put_stream($stream, $dumpBytes, $objectKey, $config, $checksum);
        opng_log('info', 'external_backup_completed', [
            'target' => 's3',
            'name' => $name,
            'bytes' => $dumpBytes,
            'checksum' => $checksum,
        ]);

        return [
            'target' => 's3',
            'name' => $name,
            'path' => null,
            'url' => $url,
            'checksum' => $checksum,
            'bytes' => $dumpBytes,
        ];
    } finally {
        fclose($stream);
    }
}

/**
 * Remove local backups older than the retention window.
 */
/**
 * @param array<string, mixed> $config
 */
function external_backup_prune_local(array $config): int
{
    $directory = $config['directory'];
    $files = scandir($directory);
    if ($files === false) {
        return 0;
    }
    $threshold = time() - ($config['retention_days'] * 86400);
    $removed = 0;
    foreach ($files as $name) {
        if (!preg_match('/\Aopng-[0-9]{8}-[0-9]{6}-[0-9a-f]{8}\.(?:sql|enc)\z/', $name)) {
            continue;
        }
        $file = $directory . '/' . $name;
        if (is_link($file) || !is_file($file)) {
            continue;
        }
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $threshold && @unlink($file)) {
            $sidecar = $file . '.sha256';
            if (!is_link($sidecar) && is_file($sidecar)) {
                @unlink($sidecar);
            }
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
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Backup temporary stream is unavailable.');
    }
    try {
        external_backup_write_all($stream, $body);

        return external_backup_s3_put_stream($stream, strlen($body), $objectKey, $config);
    } finally {
        fclose($stream);
    }
}

/**
 * Upload a seekable stream with SigV4 without materializing its body.
 *
 * @param resource $stream
 * @param array<string, mixed> $config
 */
function external_backup_s3_put_stream(
    $stream,
    int $size,
    string $objectKey,
    array $config,
    ?string $payloadHash = null
): string {
    if ($size < 0) {
        throw new RuntimeException('S3 upload size is invalid.');
    }
    $endpoint = rtrim($config['s3_endpoint'], '/');
    $parsed = parse_url($endpoint);
    if (!is_array($parsed) || !is_string($parsed['host'] ?? null)) {
        throw new RuntimeException('S3 endpoint is invalid.');
    }
    $scheme = strtolower((string) ($parsed['scheme'] ?? 'https'));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('S3 endpoint must use HTTP or HTTPS.');
    }
    $host = $parsed['host'];
    $portNumber = isset($parsed['port'])
        ? (int) $parsed['port']
        : ($scheme === 'https' ? 443 : 80);
    $portSuffix = isset($parsed['port']) ? ':' . (int) $parsed['port'] : '';
    $basePath = rtrim((string) ($parsed['path'] ?? ''), '/');
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', $objectKey)));

    if ($config['s3_path_style']) {
        $canonicalUri = $basePath . '/' . rawurlencode($config['s3_bucket']) . '/' . $encodedKey;
        $hostHeader = $host . $portSuffix;
        $connectHost = $host;
    } else {
        $canonicalUri = $basePath . '/' . $encodedKey;
        $hostHeader = $config['s3_bucket'] . '.' . $host . $portSuffix;
        $connectHost = $config['s3_bucket'] . '.' . $host;
    }
    $now = time();
    $amzDate = gmdate('Ymd\THis\Z', $now);
    $dateStamp = gmdate('Ymd', $now);
    $payloadHash = $payloadHash ?? external_backup_stream_checksum($stream)['checksum'];
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
        'Content-Length: ' . $size,
    ];
    $contextOptions = [];
    if ($scheme === 'https') {
        $contextOptions['ssl'] = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $connectHost,
            'SNI_enabled' => true,
        ];
    }
    $context = stream_context_create($contextOptions);
    $transport = $scheme === 'https' ? 'ssl' : 'tcp';
    $socket = @stream_socket_client(
        $transport . '://' . $connectHost . ':' . $portNumber,
        $errno,
        $errstr,
        (float) $config['timeout'],
        STREAM_CLIENT_CONNECT,
        $context
    );
    if ($socket === false) {
        throw new RuntimeException('S3 upload connection failed: ' . $errstr);
    }
    stream_set_timeout($socket, (int) $config['timeout']);
    try {
        $request = "PUT {$canonicalUri} HTTP/1.1\r\n"
            . implode("\r\n", $headerLines)
            . "\r\nConnection: close\r\n\r\n";
        external_backup_write_all($socket, $request);
        if (fseek($stream, 0) !== 0) {
            throw new RuntimeException('Backup stream is not seekable.');
        }
        $sent = 0;
        while ($sent < $size) {
            $chunk = fread($stream, min(OPNG_EXTERNAL_BACKUP_CHUNK_BYTES, $size - $sent));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Backup stream ended before its declared size.');
            }
            external_backup_write_all($socket, $chunk);
            $sent += strlen($chunk);
        }
        $statusLine = fgets($socket, 8192);
        if (
            !is_string($statusLine)
            || preg_match('/\AHTTP\/\S+\s+(\d{3})/', $statusLine, $match) !== 1
        ) {
            throw new RuntimeException('S3 upload returned an invalid response.');
        }
        $status = (int) $match[1];
        while (($line = fgets($socket, 8192)) !== false) {
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
        }
        $body = '';
        while (!feof($socket) && strlen($body) < 8192) {
            $part = fread($socket, 8192);
            if ($part === false || $part === '') {
                break;
            }
            $body .= $part;
        }
        if ($status >= 300) {
            throw new RuntimeException(
                'S3 upload failed: ' . trim($statusLine) . ' ' . trim(substr($body, 0, 512))
            );
        }
    } finally {
        fclose($socket);
    }

    return $scheme . '://' . $hostHeader . $canonicalUri;
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
    $input = fopen($path, 'rb');
    if ($input === false) {
        throw new RuntimeException('Backup file could not be read.');
    }
    $plainStream = null;
    try {
        $header = fread($input, strlen(OPNG_EXTERNAL_BACKUP_MAGIC));
        if ($header === false) {
            throw new RuntimeException('Backup file could not be read.');
        }
        $encrypted = str_ends_with($path, '.enc');
        $payloadEncrypted = $header === OPNG_EXTERNAL_BACKUP_MAGIC
            || $header === OPNG_EXTERNAL_BACKUP_MAGIC_V1;
        if ($encrypted !== $payloadEncrypted) {
            throw new RuntimeException('Backup encryption marker does not match the file format.');
        }

        if ($encrypted) {
            $plainStream = tmpfile();
            if ($plainStream === false) {
                throw new RuntimeException('Backup temporary stream is unavailable.');
            }
            rewind($input);
            external_backup_decrypt_stream($input, $plainStream);
            $plain = $plainStream;
        } else {
            rewind($input);
            $plain = $input;
        }

        $hashed = external_backup_stream_checksum($plain);
        $checksumFile = $path . '.sha256';
        $line = is_file($checksumFile) ? file_get_contents($checksumFile) : false;
        if (
            !is_string($line)
            || !preg_match('/\A([a-f0-9]{64})  [^\r\n]+\r?\n?\z/', $line, $match)
            || !hash_equals($match[1], $hashed['checksum'])
        ) {
            throw new RuntimeException('Backup checksum is missing, invalid or does not match.');
        }

        $inventory = external_backup_snapshot_inventory_from_stream($plain);
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
            $result = external_backup_verify_stream($db, $plain, $inventory);
        } finally {
            $db->close();
        }
        $result['encrypted'] = $encrypted;

        return $result;
    } finally {
        if (is_resource($plainStream)) {
            fclose($plainStream);
        }
        fclose($input);
    }
}

/**
 * @param array<string|int, mixed> $tables
 * @return array<string|int, int>
 */
function external_backup_decode_inventory(array $tables): array
{
    if ($tables === []) {
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
 * @return array<string|int, int>
 */
function external_backup_snapshot_inventory(string $sql): array
{
    if (!preg_match('/^-- OPNG-SNAPSHOT-v1 (\{[^\r\n]*\})\r?\n?\z/m', $sql, $match)) {
        throw new RuntimeException('Snapshot inventory missing; completeness cannot be verified.');
    }
    $tables = json_decode($match[1], true);
    if (!is_array($tables)) {
        throw new RuntimeException('Invalid snapshot inventory.');
    }

    return external_backup_decode_inventory($tables);
}

/**
 * Read the inventory from the tail of a plaintext dump stream.
 *
 * @param resource $stream
 * @return array<string|int, int>
 */
function external_backup_snapshot_inventory_from_stream($stream): array
{
    $stat = fstat($stream);
    $size = is_array($stat) ? (int) $stat['size'] : -1;
    if ($size < 0) {
        throw new RuntimeException('Snapshot inventory is unavailable.');
    }
    $tailLength = min($size, 1048576);
    if (fseek($stream, $size - $tailLength) !== 0) {
        throw new RuntimeException('Snapshot inventory is unavailable.');
    }
    $tail = stream_get_contents($stream, $tailLength);
    if (!is_string($tail) || !preg_match('/^-- OPNG-SNAPSHOT-v1 (\{[^\r\n]*\})\r?\n?\z/m', $tail, $match)) {
        throw new RuntimeException('Snapshot inventory missing; completeness cannot be verified.');
    }
    $tables = json_decode($match[1], true);
    if (!is_array($tables)) {
        throw new RuntimeException('Invalid snapshot inventory.');
    }

    return external_backup_decode_inventory($tables);
}

/**
 * Import on a dedicated verifier connection and compare snapshot counts.
 *
 * @param array<string|int, int> $inventory
 * @return array{tables: int, rows: int}
 */
function external_backup_verify_sql(mysqli $db, string $sql, array $inventory): array
{
    $stream = fopen('php://temp/maxmemory:4194304', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Backup temporary stream is unavailable.');
    }
    try {
        external_backup_write_all($stream, $sql);

        return external_backup_verify_stream($db, $stream, $inventory);
    } finally {
        fclose($stream);
    }
}

/**
 * Import a plaintext dump stream without materializing it.
 *
 * @param resource $stream
 * @param array<string|int, int> $inventory
 * @return array{tables: int, rows: int}
 */
function external_backup_verify_stream(mysqli $db, $stream, array $inventory): array
{
    $temporaryDatabase = 'opng_verify_' . bin2hex(random_bytes(6));
    if (!$db->query("CREATE DATABASE `{$temporaryDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        throw new RuntimeException('Restore verification requires CREATE DATABASE privileges.');
    }

    try {
        if (!$db->select_db($temporaryDatabase)) {
            throw new RuntimeException('The disposable verification database could not be selected.');
        }
        if (fseek($stream, 0) !== 0) {
            throw new RuntimeException('Backup stream is not seekable.');
        }
        schema_split_sql_stream($stream, static function (string $statement) use ($db): void {
            if (!$db->query($statement)) {
                throw new RuntimeException('Restore verification import failed: ' . $db->error);
            }
        });

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

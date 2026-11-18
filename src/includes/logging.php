<?php

declare(strict_types=1);

// includes/logging.php
// Structured application logs and request correlation (10.6).
//
// Every log line is a single JSON object so a container or journal collector
// can parse it without a custom format. The request id is taken from an
// incoming X-Request-ID header only when it is short and URL-safe; otherwise a
// fresh random id is generated. The same id is returned in the response and in
// every error/audit line for that request.

const OPNG_REQUEST_ID_HEADER = 'X-Request-ID';
const OPNG_LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];

/**
 * Current request id (created on first use).
 */
function opng_request_id(): string
{
    static $request_id = null;
    if (is_string($request_id)) {
        return $request_id;
    }

    $candidate = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
    if (
        is_string($candidate)
        && preg_match('/\A[A-Za-z0-9._-]{8,64}\z/', $candidate) === 1
    ) {
        $request_id = $candidate;
    } else {
        $request_id = bin2hex(random_bytes(8));
    }

    return $request_id;
}

/**
 * Emit one structured log line. Logging never throws: a failure to encode or
 * write is swallowed so it cannot break the request it is describing.
 *
 * @param array<string, mixed> $context
 */
function opng_log(string $level, string $message, array $context = []): void
{
    $level = strtolower($level);
    if (!in_array($level, OPNG_LOG_LEVELS, true)) {
        $level = 'info';
    }

    $payload = [
        'ts' => gmdate('c'),
        'level' => $level,
        'request_id' => opng_request_id(),
        'message' => $message,
        'context' => $context,
    ];

    $encoded = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($encoded)) {
        return;
    }

    error_log($encoded);
}

/**
 * Log a caught exception with its type and message (never its stack trace, so
 * credentials in local variables cannot leak into the log).
 *
 * @param array<string, mixed> $context
 */
function opng_log_exception(string $message, Throwable $exception, array $context = []): void
{
    $context['exception'] = $exception::class;
    $context['error'] = $exception->getMessage();
    opng_log('error', $message, $context);
}

/**
 * Record a rate-limit decision. Limited and Unavailable are warnings because
 * they deny a user action and usually deserve operator attention.
 */
/**
 * @param array<string, mixed> $context
 */
function opng_observe_rate_limit(string $action, string $result, array $context = []): void
{
    $context['action'] = $action;
    $context['result'] = $result;
    $level = $result === 'allowed' ? 'debug' : 'warning';
    opng_log($level, 'rate_limit', $context);
}

/**
 * Record quota usage at the moment a write is rejected or approaches the cap.
 */
/**
 * @param array<string, mixed> $context
 */
function opng_observe_quota(string $scope, int $used, int $limit, array $context = []): void
{
    $context['scope'] = $scope;
    $context['used'] = $used;
    $context['limit'] = $limit;
    $context['percent'] = $limit > 0 ? round(($used / $limit) * 100, 2) : 0.0;
    opng_log($used >= $limit ? 'warning' : 'debug', 'quota_usage', $context);
}

/**
 * Add the request id to the response. Called from the shared layout before any
 * output is sent; repeated calls are harmless.
 */
function opng_send_request_id_header(): void
{
    if (!headers_sent()) {
        header(OPNG_REQUEST_ID_HEADER . ': ' . opng_request_id());
    }
}

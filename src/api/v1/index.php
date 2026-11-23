<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

// api/v1/index.php
// Read-only public API (10.4).
//
// Authentication: `Authorization: Bearer opng_...` with the `read` scope.
// The v1 surface deliberately exposes only public entries; private and
// unlisted threads are never returned by the API.
require_once('../../includes/api_tokens.php');
require_once('../../includes/entry_access.php');
require_once('../../includes/ratelimit.php');
require_once('../../includes/topic_search.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
opng_send_request_id_header();

/**
 * @param array<string, mixed> $payload
 */
function api_respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function api_error(int $status, string $code, string $message): never
{
    api_respond($status, [
        'error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => opng_request_id(),
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    api_error(405, 'method_not_allowed', 'Only GET requests are supported.');
}

$db = database_connect();
if (!$db) {
    api_error(503, 'database_unavailable', 'The service is temporarily unavailable.');
}

$token = api_token_bearer_from_server();
$identity = api_token_authenticate($db, $token);
if ($identity === null) {
    header('WWW-Authenticate: Bearer realm="opng-api"');
    $db->close();
    api_error(401, 'invalid_token', 'A valid bearer token is required.');
}
if (!api_token_scope_allows($identity['scopes'], 'read')) {
    $db->close();
    api_error(403, 'insufficient_scope', 'This token does not grant the read scope.');
}

$limit_result = rate_limit_hit($db, 'api_v1', 'token:' . $identity['token_id'], 120, 60);
if ($limit_result === RateLimitResult::Unavailable) {
    opng_observe_rate_limit('api_v1', 'unavailable', ['token_id' => $identity['token_id']]);
    $db->close();
    api_error(503, 'rate_limit_unavailable', 'The service is temporarily unavailable.');
}
if ($limit_result === RateLimitResult::Limited) {
    opng_observe_rate_limit('api_v1', 'limited', ['token_id' => $identity['token_id']]);
    header('Retry-After: 60');
    $db->close();
    api_error(429, 'rate_limited', 'Too many requests.');
}

$uri_path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$uri_path = is_string($uri_path) ? $uri_path : '';
$marker = '/api/v1';
$marker_position = strpos($uri_path, $marker);
$route = $marker_position === false
    ? ''
    : substr($uri_path, $marker_position + strlen($marker));
$route = trim((string) $route, '/');
$segments = $route === '' ? [] : explode('/', $route);

try {
    if ($segments === [] || $segments === ['me']) {
        api_respond(200, [
            'data' => [
                'username' => $identity['username'],
                'role' => $identity['role'],
                'scopes' => array_values(array_filter(explode(',', $identity['scopes']))),
            ],
        ]);
    }

    if ($segments[0] !== 'entries' || count($segments) > 2) {
        api_error(404, 'not_found', 'Unknown API route.');
    }

    if (count($segments) === 1) {
        $limit_value = http_positive_integer($_GET['limit'] ?? null) ?? 20;
        $limit_value = min($limit_value, 100);
        $offset_value = http_positive_integer($_GET['offset'] ?? null) ?? 0;
        if ($offset_value > 10000) {
            api_error(400, 'invalid_offset', 'The offset is too large.');
        }

        $conditions = [entry_public_filter_sql('entries')];
        $params = [$limit_value, $offset_value];
        $types = 'ii';
        $topic_filter = http_string_parameter($_GET, 'topic', OPNG_MAX_SEARCH_QUERY_BYTES);
        if ($topic_filter !== null && $topic_filter !== '') {
            $topic_expression = topic_search_expression($topic_filter);
            if ($topic_expression === null) {
                api_error(400, 'invalid_topic', 'The topic filter is invalid.');
            }
            $conditions[] = 'MATCH(entries.topic) AGAINST (? IN BOOLEAN MODE)';
            array_unshift($params, $topic_expression);
            $types = 's' . $types;
        }

        $stmt = $db->prepare(
            'SELECT id, topic, language, date, author_label
             FROM entries
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY date DESC, id DESC
             LIMIT ? OFFSET ?'
        );
        if (!$stmt) {
            throw new RuntimeException('API listing statement could not be prepared.');
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new RuntimeException('API listing query failed.');
        }
        $result = $stmt->get_result();
        $entries = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $entries[] = [
                'id' => (string) $row['id'],
                'topic' => (string) $row['topic'],
                'language' => (string) $row['language'],
                'author' => (string) ($row['author_label'] ?? 'Guest'),
                'date' => (string) $row['date'],
            ];
        }
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $stmt->close();

        api_respond(200, [
            'data' => $entries,
            'meta' => [
                'count' => count($entries),
                'limit' => $limit_value,
                'offset' => $offset_value,
            ],
        ]);
    }

    $entry_id = $segments[1];
    if (!http_valid_entry_id($entry_id)) {
        api_error(404, 'not_found', 'Entry not found.');
    }

    $stmt = $db->prepare(
        'SELECT id, topic, language, date, author_label, text
         FROM entries
         WHERE id = ? AND ' . entry_api_content_filter_sql() . ' LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('API entry statement could not be prepared.');
    }
    $stmt->bind_param('s', $entry_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('API entry query failed.');
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        api_error(404, 'not_found', 'Entry not found.');
    }

    api_respond(200, [
        'data' => [
            'id' => (string) $row['id'],
            'topic' => (string) $row['topic'],
            'language' => (string) $row['language'],
            'author' => (string) ($row['author_label'] ?? 'Guest'),
            'date' => (string) $row['date'],
            'text' => (string) $row['text'],
        ],
    ]);
} catch (mysqli_sql_exception | RuntimeException $exception) {
    opng_log_exception('api_v1_request_failed', $exception, [
        'token_id' => $identity['token_id'],
        'route' => $route,
    ]);
    $db->close();
    api_error(500, 'internal_error', 'The request could not be completed.');
}

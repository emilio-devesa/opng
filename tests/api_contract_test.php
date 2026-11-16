<?php

declare(strict_types=1);

/**
 * API token and read-only API contract regression test (10.4).
 *
 * Pure helpers and source contracts only; the database integration test
 * exercises the real token lifecycle.
 */

require __DIR__ . '/../src/includes/api_tokens.php';

function api_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function api_pass(string $message): void
{
    echo "PASS: {$message}\n";
}

function api_assert(bool $condition, string $message): void
{
    if (!$condition) {
        api_fail($message);
    }
    api_pass($message);
}

$token = api_token_generate();
api_assert(api_token_valid_format($token['clear']), 'generated tokens use the opng_ prefix and 64 hex characters');
api_assert($token['hash'] === hash('sha256', $token['clear']), 'only the SHA-256 hash is stored');
api_assert(!api_token_valid_format('opng_short'), 'short tokens are rejected');
api_assert(!api_token_valid_format('bearer ' . $token['clear']), 'prefixed values are rejected');

api_assert(api_token_normalize_scopes('read') === 'read', 'a single scope normalizes');
api_assert(api_token_normalize_scopes('write,read') === 'read,write', 'scopes are deduplicated and sorted');
api_assert(api_token_normalize_scopes(['READ']) === 'read', 'array scopes are normalized');
api_assert(api_token_normalize_scopes('read,admin') === null, 'unknown scopes are rejected');
api_assert(api_token_normalize_scopes([]) === null, 'empty scope lists are rejected');
api_assert(api_token_normalize_scopes('') === null, 'blank scopes are rejected');

api_assert(api_token_scope_allows('read,write', 'read'), 'the read scope is recognized');
api_assert(api_token_scope_allows('read,write', 'write'), 'the write scope is recognized');
api_assert(!api_token_scope_allows('read', 'write'), 'missing scopes are rejected');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token['clear'];
api_assert(api_token_bearer_from_server() === $token['clear'], 'bearer tokens are read from the Authorization header');
$_SERVER['HTTP_AUTHORIZATION'] = 'Basic abc';
api_assert(api_token_bearer_from_server() === null, 'non-bearer credentials are rejected');
unset($_SERVER['HTTP_AUTHORIZATION']);

$router = (string) file_get_contents(__DIR__ . '/../src/api/v1/index.php');
api_assert($router !== '', 'the API router exists');
api_assert(
    str_contains($router, "require_once('../../includes/api_tokens.php')")
        && str_contains($router, 'api_token_authenticate($db, $token)'),
    'the router authenticates bearer tokens'
);
api_assert(
    str_contains($router, "api_token_scope_allows(\$identity['scopes'], 'read')"),
    'the v1 surface requires the read scope'
);
api_assert(
    str_contains($router, "rate_limit_hit(\$db, 'api_v1'")
        && str_contains($router, 'opng_observe_rate_limit('),
    'API requests are rate limited and observed'
);
api_assert(
    str_contains($router, "if (\$_SERVER['REQUEST_METHOD'] !== 'GET')"),
    'the API accepts GET requests only'
);
api_assert(
    str_contains($router, "entry_public_filter_sql('entries')"),
    'the API reuses the public visibility filter'
);
api_assert(
    preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+|DELETE\s+FROM)\b/i', $router) !== 1,
    'the v1 API surface is read-only'
);
api_assert(
    str_contains($router, "'/entries'") || str_contains($router, "segments[0] !== 'entries'"),
    'the router exposes the entries collection'
);

$htaccess = (string) file_get_contents(__DIR__ . '/../src/api/v1/.htaccess');
api_assert(
    str_contains($htaccess, 'RewriteRule ^ index.php [QSA,L]'),
    'the API front controller rewrites unknown paths to index.php'
);
api_assert(
    str_contains($htaccess, 'Options -Indexes'),
    'directory listings are disabled for the API'
);

echo "API contract regression passed: token format, scopes, bearer auth and read-only routes.\n";

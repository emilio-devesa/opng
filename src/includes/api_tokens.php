<?php

declare(strict_types=1);

// includes/api_tokens.php
// Personal read-only API tokens (10.4).
//
// The bearer token is shown once. Only its SHA-256 hash is stored, so a database
// dump cannot be replayed against the API. Revocation keeps the row for the
// audit trail instead of deleting it.

const OPNG_API_TOKEN_PREFIX = 'opng_';
const OPNG_API_TOKEN_SCOPES = ['read', 'write'];
const OPNG_API_TOKEN_MAX_DAYS = 365;

/**
 * @return array{clear: string, hash: string}
 */
function api_token_generate(): array
{
    $clear = OPNG_API_TOKEN_PREFIX . bin2hex(random_bytes(32));

    return ['clear' => $clear, 'hash' => hash('sha256', $clear)];
}

function api_token_valid_format(string $token): bool
{
    return preg_match('/\Aopng_[a-f0-9]{64}\z/', $token) === 1;
}

/**
 * Validate requested scopes and return a canonical comma-separated list.
 */
function api_token_normalize_scopes(mixed $scopes): ?string
{
    if (is_string($scopes)) {
        $requested = array_values(array_filter(
            array_map('trim', explode(',', strtolower($scopes))),
            static fn (string $scope): bool => $scope !== ''
        ));
    } elseif (is_array($scopes)) {
        $requested = array_values(array_filter(
            array_map(static fn ($scope): string => strtolower(trim((string) $scope)), $scopes),
            static fn (string $scope): bool => $scope !== ''
        ));
    } else {
        return null;
    }

    $requested = array_values(array_unique($requested));
    if ($requested === [] || array_diff($requested, OPNG_API_TOKEN_SCOPES) !== []) {
        return null;
    }
    sort($requested);

    return implode(',', $requested);
}

/**
 * Create a token and return the clear value exactly once.
 *
 * @return array{id: int, clear: string}|null
 */
function api_token_create(
    mysqli $db,
    int $userId,
    string $name,
    mixed $scopes = 'read',
    ?int $expiresInDays = null
): ?array {
    $name = trim($name);
    if ($name === '' || strlen($name) > 80) {
        return null;
    }
    $normalizedScopes = api_token_normalize_scopes($scopes);
    if ($normalizedScopes === null) {
        return null;
    }
    if ($expiresInDays !== null && ($expiresInDays < 1 || $expiresInDays > OPNG_API_TOKEN_MAX_DAYS)) {
        return null;
    }

    $token = api_token_generate();
    $expiresAt = $expiresInDays === null
        ? null
        : gmdate('Y-m-d H:i:s', time() + ($expiresInDays * 86400));

    $stmt = $db->prepare(
        'INSERT INTO api_tokens (user_id, name, token_hash, scopes, expires_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('issss', $userId, $name, $token['hash'], $normalizedScopes, $expiresAt);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $id = (int) $db->insert_id;
    $stmt->close();

    return ['id' => $id, 'clear' => $token['clear']];
}

/**
 * @return list<array{id: int, name: string, scopes: string, created_at: string, last_used_at: string|null, expires_at: string|null, revoked_at: string|null, username: string}>
 */
function api_token_list(mysqli $db, ?int $userId = null, int $limit = 100): array
{
    $limit = max(1, min($limit, 500));
    $sql = 'SELECT tokens.id, tokens.user_id, tokens.name, tokens.scopes, tokens.created_at,
                   tokens.last_used_at, tokens.expires_at, tokens.revoked_at, users.username
            FROM api_tokens tokens
            JOIN users ON users.id = tokens.user_id';
    if ($userId !== null) {
        $sql .= ' WHERE tokens.user_id = ?';
    }
    $sql .= ' ORDER BY tokens.created_at DESC, tokens.id DESC LIMIT ' . $limit;

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($userId !== null) {
        $stmt->bind_param('i', $userId);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $tokens = [];
    while ($row = $result->fetch_assoc()) {
        $tokens[] = [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'scopes' => (string) ($row['scopes'] ?? 'read'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'last_used_at' => is_string($row['last_used_at'] ?? null) ? $row['last_used_at'] : null,
            'expires_at' => is_string($row['expires_at'] ?? null) ? $row['expires_at'] : null,
            'revoked_at' => is_string($row['revoked_at'] ?? null) ? $row['revoked_at'] : null,
            'username' => (string) ($row['username'] ?? ''),
        ];
    }
    $result->free();
    $stmt->close();

    return $tokens;
}

/**
 * Revoke a token. A null owner id allows administrators to revoke any token.
 */
function api_token_revoke(mysqli $db, int $tokenId, ?int $ownerId = null): bool
{
    $sql = 'UPDATE api_tokens SET revoked_at = NOW()
            WHERE id = ? AND revoked_at IS NULL';
    if ($ownerId !== null) {
        $sql .= ' AND user_id = ?';
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return false;
    }
    if ($ownerId !== null) {
        $stmt->bind_param('ii', $tokenId, $ownerId);
    } else {
        $stmt->bind_param('i', $tokenId);
    }
    $stmt->execute();
    $revoked = $stmt->affected_rows === 1;
    $stmt->close();

    return $revoked;
}

/**
 * Read the bearer token from the Authorization header.
 */
function api_token_bearer_from_server(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if (!is_string($header)) {
        return null;
    }
    if (preg_match('/\ABearer\s+([A-Za-z0-9_]+)\z/i', trim($header), $match) !== 1) {
        return null;
    }

    return $match[1];
}

/**
 * Authenticate a bearer token. Recording use is a separate, admitted step so
 * rate-limited requests do not amplify writes (P04).
 *
 * @return array{token_id: int, user_id: int, username: string, role: string, scopes: string}|null
 */
function api_token_authenticate(mysqli $db, ?string $token): ?array
{
    if (!is_string($token) || !api_token_valid_format($token)) {
        return null;
    }

    $hash = hash('sha256', $token);
    $stmt = $db->prepare(
        'SELECT tokens.id, tokens.user_id, tokens.scopes, tokens.expires_at, tokens.revoked_at,
                users.username, users.role
         FROM api_tokens tokens
         JOIN users ON users.id = tokens.user_id
         WHERE tokens.token_hash = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $hash);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();
    if (!is_array($row)) {
        return null;
    }
    if (is_string($row['revoked_at'] ?? null) && $row['revoked_at'] !== '') {
        return null;
    }
    if (is_string($row['expires_at'] ?? null) && $row['expires_at'] !== '') {
        try {
            if ((new DateTimeImmutable($row['expires_at']))->getTimestamp() <= time()) {
                return null;
            }
        } catch (Exception) {
            return null;
        }
    }

    $tokenId = (int) $row['id'];

    return [
        'token_id' => $tokenId,
        'user_id' => (int) $row['user_id'],
        'username' => (string) ($row['username'] ?? ''),
        'role' => (string) ($row['role'] ?? 'user'),
        'scopes' => (string) ($row['scopes'] ?? 'read'),
    ];
}

/**
 * Record an admitted use. last_used_at means the last admitted request, not
 * every attempt, and is refreshed at most once per minute per token so a
 * read-only API does not turn every read into a row write (P04).
 */
function api_token_touch(mysqli $db, int $tokenId): bool
{
    $stmt = $db->prepare(
        'UPDATE api_tokens SET last_used_at = NOW()
         WHERE id = ?
           AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 60 SECOND)'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $tokenId);
    $ok = $stmt->execute();
    $stmt->close();

    return (bool) $ok;
}

function api_token_scope_allows(string $scopes, string $required): bool
{
    $granted = array_map('trim', explode(',', strtolower($scopes)));

    return in_array($required, $granted, true);
}

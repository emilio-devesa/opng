<?php

const OPNG_USER_QUOTA_BYTES = 100 * 1024 * 1024;

/**
 * Lock the account row so concurrent paste writes cannot exceed its quota.
 *
 * @return array{role: string, used_bytes: int}|null
 */
function lock_user_quota(mysqli $db, int $userId): ?array
{
    $stmt = $db->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
    try {
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            throw new mysqli_sql_exception('Account quota lookup failed.');
        }
        $result = $stmt->get_result();
        if (!$result) {
            throw new mysqli_sql_exception('Account quota result is unavailable.');
        }
        $account = $result->fetch_assoc();
        $result->free();
    } finally {
        $stmt->close();
    }

    if ($account === null) {
        return null;
    }

    $role = (string) $account['role'];
    if ($role === 'admin') {
        return ['role' => $role, 'used_bytes' => 0];
    }
    if ($role !== 'user') {
        return ['role' => $role, 'used_bytes' => 0];
    }

    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(OCTET_LENGTH(text)), 0) AS used_bytes FROM entries WHERE user_id = ?'
    );
    try {
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            throw new mysqli_sql_exception('Account usage lookup failed.');
        }
        $result = $stmt->get_result();
        if (!$result) {
            throw new mysqli_sql_exception('Account usage result is unavailable.');
        }
        $usage = $result->fetch_assoc();
        $result->free();
    } finally {
        $stmt->close();
    }

    return [
        'role' => $role,
        'used_bytes' => (int) ($usage['used_bytes'] ?? 0),
    ];
}

/**
 * @param array{role: string, used_bytes: int} $usage
 */
function user_quota_allows(array $usage, int $previousBytes, int $replacementBytes): bool
{
    if ($usage['role'] === 'admin') {
        return true;
    }
    if (
        $usage['role'] !== 'user'
        || $previousBytes < 0
        || $replacementBytes < 0
        || $previousBytes > $usage['used_bytes']
    ) {
        return false;
    }

    if ($usage['used_bytes'] > OPNG_USER_QUOTA_BYTES) {
        return $replacementBytes <= $previousBytes;
    }

    return $usage['used_bytes'] - $previousBytes + $replacementBytes <= OPNG_USER_QUOTA_BYTES;
}

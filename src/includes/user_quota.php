<?php

const OPNG_USER_QUOTA_BYTES = 100 * 1024 * 1024;
const OPNG_DEFAULT_GLOBAL_QUOTA_BYTES = 10 * 1024 * 1024 * 1024;

/**
 * Global paste-text quota. Override with OPNG_STORAGE_QUOTA_BYTES (bytes).
 */
function global_storage_quota_bytes(): int
{
    $raw = getenv('OPNG_STORAGE_QUOTA_BYTES');
    if ($raw === false || $raw === '') {
        return OPNG_DEFAULT_GLOBAL_QUOTA_BYTES;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $value === false ? OPNG_DEFAULT_GLOBAL_QUOTA_BYTES : $value;
}

/**
 * Current total bytes of paste text, or null when the query fails.
 */
function global_storage_usage(mysqli $db): ?int
{
    $result = $db->query('SELECT COALESCE(SUM(OCTET_LENGTH(text)), 0) AS used_bytes FROM entries');
    if (!$result) {
        return null;
    }

    $row = $result->fetch_assoc();
    $result->free();

    return is_array($row) ? (int) ($row['used_bytes'] ?? 0) : null;
}

function global_quota_allows(int $usedBytes, int $newBytes, ?int $quotaBytes = null): bool
{
    if ($usedBytes < 0 || $newBytes < 0) {
        return false;
    }

    $quota = $quotaBytes ?? global_storage_quota_bytes();

    return $usedBytes + $newBytes <= $quota;
}

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

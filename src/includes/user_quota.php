<?php

declare(strict_types=1);

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
 *
 * Maintenance/reconciliation helper. Request paths use the O(1) counter
 * functions below instead of scanning `entries` on every write.
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

/**
 * Lock the `storage_counters` row for the global storage quota and return the
 * current usage. Must be called inside a transaction. The first call after an
 * upgrade seeds the counter from SUM() once; later calls are O(1), which
 * removes the full-table aggregate from every paste write (PERF-01).
 */
function global_quota_lock(mysqli $db): ?int
{
    $key = 'global';

    $read = static function (mysqli $db, string $key): ?array {
        $stmt = $db->prepare(
            'SELECT used_bytes FROM storage_counters WHERE counter_key = ? FOR UPDATE'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $key);
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

        return is_array($row) ? $row : null;
    };

    $row = $read($db, $key);
    if ($row === null) {
        // Seed once. INSERT IGNORE keeps two concurrent first writes safe.
        $db->query(
            "INSERT IGNORE INTO storage_counters (counter_key, used_bytes)
             SELECT 'global', COALESCE(SUM(OCTET_LENGTH(text)), 0) FROM entries"
        );
        $row = $read($db, $key);
    }

    return is_array($row) ? (int) ($row['used_bytes'] ?? 0) : null;
}

/**
 * Apply a signed byte delta to the locked global counter. Negative deltas are
 * clamped at zero. Must run inside the same transaction as the entry write.
 */
function global_quota_consume(mysqli $db, int $delta): bool
{
    $stmt = $db->prepare(
        "UPDATE storage_counters
         SET used_bytes = GREATEST(CAST(used_bytes AS SIGNED) + ?, 0)
         WHERE counter_key = 'global'"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $delta);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/**
 * Reset the global counter to zero after deleting every entry.
 */
function global_quota_zero(mysqli $db): bool
{
    $result = $db->query("UPDATE storage_counters SET used_bytes = 0 WHERE counter_key = 'global'");

    return $result !== false;
}

/**
 * Rebuild the counter from the authoritative SUM(). Used by maintenance tools
 * and after bulk data imports that bypass the application.
 */
function global_quota_recount(mysqli $db): bool
{
    $result = $db->query(
        "UPDATE storage_counters
         SET used_bytes = (SELECT COALESCE(SUM(OCTET_LENGTH(text)), 0) FROM entries)
         WHERE counter_key = 'global'"
    );

    return $result !== false;
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

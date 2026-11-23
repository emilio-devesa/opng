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
            "INSERT IGNORE INTO storage_counters (counter_key, used_bytes, used_entries)
             SELECT 'global', COALESCE(SUM(OCTET_LENGTH(text)), 0), COUNT(*) FROM entries"
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
 * Current number of stored entries, or null when the query fails.
 */
function global_entry_usage(mysqli $db): ?int
{
    $result = $db->query('SELECT COUNT(*) AS used_entries FROM entries');
    if (!$result) {
        return null;
    }

    $row = $result->fetch_assoc();
    $result->free();

    return is_array($row) ? (int) ($row['used_entries'] ?? 0) : null;
}

/**
 * Apply a signed delta to the locked global entry counter. Negative deltas are
 * clamped at zero. Must run inside the same transaction as the entry write.
 */
function global_entry_consume(mysqli $db, int $delta): bool
{
    $stmt = $db->prepare(
        "UPDATE storage_counters
         SET used_entries = GREATEST(CAST(used_entries AS SIGNED) + ?, 0)
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
 * Reset the global counters to zero after deleting every entry.
 */
function global_quota_zero(mysqli $db): bool
{
    $result = $db->query(
        "UPDATE storage_counters SET used_bytes = 0, used_entries = 0 WHERE counter_key = 'global'"
    );

    return $result !== false;
}

/**
 * Rebuild the counters from the authoritative data. Used by maintenance tools
 * and after bulk data imports that bypass the application.
 */
function global_quota_recount(mysqli $db): bool
{
    $result = $db->query(
        "UPDATE storage_counters
         SET used_bytes = (SELECT COALESCE(SUM(OCTET_LENGTH(text)), 0) FROM entries),
             used_entries = (SELECT COUNT(*) FROM entries)
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
 * The byte usage comes from the `users.used_bytes` counter maintained by the
 * same transaction as each write, so locking an account no longer scans its
 * entries (PERF-10). The username is returned for the entry author label.
 *
 * @return array{role: string, used_bytes: int, username: string, session_version: int}|null
 */
function lock_user_quota(mysqli $db, int $userId): ?array
{
    $stmt = $db->prepare('SELECT role, used_bytes, username, session_version FROM users WHERE id = ? FOR UPDATE');
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

    return [
        'role' => (string) $account['role'],
        'used_bytes' => (int) ($account['used_bytes'] ?? 0),
        'session_version' => (int) $account['session_version'],
        'username' => is_string($account['username'] ?? null) ? $account['username'] : 'Guest',
    ];
}

/**
 * Apply a signed byte delta to a locked account counter. Negative deltas are
 * clamped at zero. Must run inside the same transaction as the entry write and
 * after the account row has been locked by lock_user_quota().
 */
function user_quota_consume(mysqli $db, int $userId, int $delta): bool
{
    $stmt = $db->prepare(
        'UPDATE users
         SET used_bytes = GREATEST(CAST(used_bytes AS SIGNED) + ?, 0)
         WHERE id = ?'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $delta, $userId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/**
 * Reset every account counter after deleting all entries.
 */
function user_quota_zero_all(mysqli $db): bool
{
    $result = $db->query('UPDATE users SET used_bytes = 0');

    return $result !== false;
}

/**
 * Rebuild every account counter from the authoritative SUM().
 */
function user_quota_recount(mysqli $db): bool
{
    $result = $db->query(
        'UPDATE users u
         LEFT JOIN (
             SELECT user_id, COALESCE(SUM(OCTET_LENGTH(text)), 0) AS used_bytes
             FROM entries
             WHERE user_id IS NOT NULL
             GROUP BY user_id
         ) AS entry_usage ON entry_usage.user_id = u.id
         SET u.used_bytes = COALESCE(entry_usage.used_bytes, 0)'
    );

    return $result !== false;
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

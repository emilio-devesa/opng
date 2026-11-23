<?php

declare(strict_types=1);

// includes/trash.php
// Soft deletion, restoration and scheduled purge (10.3).
//
// Deleting an entry moves the whole thread (root and every reply) to the trash.
// Storage and quota counters stay charged until the purge physically removes
// the rows, so a restore never needs to re-check quotas and an author cannot
// free quota by deleting and restoring repeatedly.
//
// The purge locks resources in the global order
// storage_counters -> users -> entries.

require_once __DIR__ . '/user_quota.php';
require_once __DIR__ . '/logging.php';

const OPNG_TRASH_RETENTION_DAYS = 7;
const OPNG_TRASH_PURGE_BATCH = 500;

function trash_retention_days(): int
{
    $value = filter_var(getenv('OPNG_TRASH_RETENTION_DAYS'), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 365],
    ]);

    return $value === false ? OPNG_TRASH_RETENTION_DAYS : (int) $value;
}

/**
 * Thread root for an entry. Must be called before the entry is deleted.
 */
function trash_thread_root_id(mysqli $db, string $entryId): ?string
{
    $stmt = $db->prepare('SELECT COALESCE(root_id, id) AS root_id FROM entries WHERE id = ?');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $entryId);
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

    return is_array($row) && is_string($row['root_id'] ?? null) && $row['root_id'] !== ''
        ? $row['root_id']
        : null;
}

/**
 * Move the whole thread to the trash. The caller owns the transaction and has
 * already locked the target entry and the owner account.
 *
 * @return int number of rows newly marked as deleted
 */
function trash_soft_delete_thread(mysqli $db, string $entryId, int $actorId, string $role): int
{
    $rootId = trash_thread_root_id($db, $entryId);
    if ($rootId === null) {
        return 0;
    }

    $stmt = $db->prepare(
        "UPDATE entries member
         LEFT JOIN entries root ON root.id = ?
         SET member.deleted_at = NOW()
         WHERE (member.id = ? OR member.root_id = ?) AND member.deleted_at IS NULL
           AND (? = 'admin' OR (? = 'user' AND root.user_id = ?))"
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('sssssi', $rootId, $rootId, $rootId, $role, $role, $actorId);
    $stmt->execute();
    $updated = max(0, $stmt->affected_rows);
    $stmt->close();

    return $updated;
}

/**
 * Restore the whole thread. The caller has already checked ownership.
 *
 * @return int number of restored rows
 */
function trash_restore_thread(mysqli $db, string $entryId): int
{
    $rootId = trash_thread_root_id($db, $entryId);
    if ($rootId === null) {
        return 0;
    }

    $stmt = $db->prepare(
        'UPDATE entries SET deleted_at = NULL
         WHERE (id = ? OR root_id = ?) AND deleted_at IS NOT NULL'
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('ss', $rootId, $rootId);
    $stmt->execute();
    $updated = max(0, $stmt->affected_rows);
    $stmt->close();

    return $updated;
}

/**
 * Load one trashed thread root with the number of replies and the owner.
 *
 * @return array{id: string, user_id: int|null, topic: string, deleted_at: string, entries: int}|null
 */
function trash_thread_for_restore(mysqli $db, string $entryId): ?array
{
    $rootId = trash_thread_root_id($db, $entryId);
    if ($rootId === null) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT root.id, root.user_id, root.topic, root.deleted_at,
                (SELECT COUNT(*) FROM entries child WHERE child.id = root.id OR child.root_id = root.id) AS entries
         FROM entries root
         WHERE root.id = ? AND root.deleted_at IS NOT NULL'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $rootId);
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

    return [
        'id' => (string) $row['id'],
        'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
        'topic' => (string) ($row['topic'] ?? ''),
        'deleted_at' => (string) ($row['deleted_at'] ?? ''),
        'entries' => (int) ($row['entries'] ?? 0),
    ];
}

/**
 * List trashed thread roots, optionally restricted to one owner.
 *
 * @return list<array{id: string, user_id: int|null, topic: string, deleted_at: string, date: string, entries: int, username: string}>
 */
function trash_list_threads(mysqli $db, ?int $ownerId = null, int $limit = 100): array
{
    $limit = max(1, min($limit, 500));
    $sql = 'SELECT root.id, root.user_id, root.topic, root.deleted_at, root.date,
                   COALESCE(users.username, \'\') AS username,
                   (SELECT COUNT(*) FROM entries child WHERE child.id = root.id OR child.root_id = root.id) AS entries
            FROM entries root
            LEFT JOIN users ON users.id = root.user_id
            WHERE root.deleted_at IS NOT NULL
              AND (root.root_id = root.id OR root.root_id IS NULL)';
    if ($ownerId !== null) {
        $sql .= ' AND root.user_id = ?';
    }
    $sql .= ' ORDER BY root.deleted_at DESC, root.id DESC LIMIT ' . $limit;

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($ownerId !== null) {
        $stmt->bind_param('i', $ownerId);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $threads = [];
    while ($row = $result->fetch_assoc()) {
        $threads[] = [
            'id' => (string) $row['id'],
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'topic' => (string) ($row['topic'] ?? ''),
            'deleted_at' => (string) ($row['deleted_at'] ?? ''),
            'date' => (string) ($row['date'] ?? ''),
            'entries' => (int) ($row['entries'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
        ];
    }
    $result->free();
    $stmt->close();

    return $threads;
}

/**
 * Lock every affected account in ascending id order.
 *
 * @param array<int, int> $accounts account id => stored bytes
 */
function trash_lock_accounts(mysqli $db, array $accounts): void
{
    if ($accounts === []) {
        return;
    }

    $ids = array_keys($accounts);
    sort($ids, SORT_NUMERIC);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $lock = $db->prepare(
        "SELECT id FROM users WHERE id IN ({$placeholders}) ORDER BY id ASC FOR UPDATE"
    );
    if (!$lock) {
        throw new RuntimeException('Account lock query could not be prepared.');
    }
    $lock->bind_param(str_repeat('i', count($ids)), ...$ids);
    $lock->execute();
    $lock->get_result()->free();
    $lock->close();
}

/**
 * Physically delete every trashed thread older than the retention window.
 *
 * @return array{purged: int, bytes: int}
 */
function trash_purge_expired(mysqli $db, ?int $days = null, int $batchSize = OPNG_TRASH_PURGE_BATCH): array
{
    $days = $days ?? trash_retention_days();
    $batchSize = max(1, min($batchSize, 5000));
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    $purged = 0;
    $bytes = 0;

    $db->begin_transaction();
    try {
        if (global_quota_lock($db) === null) {
            throw new RuntimeException('Global storage counter is unavailable.');
        }

        // Aggregate before deleting so the counters receive exact deltas.
        $stmt = $db->prepare(
            'SELECT user_id, COALESCE(SUM(OCTET_LENGTH(text)), 0) AS bytes, COUNT(*) AS entries
             FROM entries
             WHERE deleted_at IS NOT NULL AND deleted_at < ?
             GROUP BY user_id'
        );
        if (!$stmt) {
            throw new RuntimeException('Trash aggregate query could not be prepared.');
        }
        $stmt->bind_param('s', $cutoff);
        $stmt->execute();
        $result = $stmt->get_result();
        $accounts = [];
        $totalBytes = 0;
        $totalEntries = 0;
        while ($row = $result->fetch_assoc()) {
            $accountId = $row['user_id'] !== null ? (int) $row['user_id'] : null;
            $accountBytes = (int) ($row['bytes'] ?? 0);
            $accountEntries = (int) ($row['entries'] ?? 0);
            $totalBytes += $accountBytes;
            $totalEntries += $accountEntries;
            if ($accountId !== null) {
                $accounts[$accountId] = $accountBytes;
            }
        }
        $result->free();
        $stmt->close();

        if ($totalEntries === 0) {
            $db->commit();
            return ['purged' => 0, 'bytes' => 0];
        }

        // Lock accounts in ascending id order, after the global counter.
        trash_lock_accounts($db, $accounts);

        do {
            $delete = $db->prepare(
                'DELETE FROM entries WHERE deleted_at IS NOT NULL AND deleted_at < ?
                 ORDER BY id LIMIT ' . $batchSize
            );
            if (!$delete) {
                throw new RuntimeException('Trash purge query could not be prepared.');
            }
            $delete->bind_param('s', $cutoff);
            $delete->execute();
            $deletedBatch = max(0, $delete->affected_rows);
            $delete->close();
            $purged += $deletedBatch;
        } while ($deletedBatch === $batchSize);

        if (
            !global_quota_consume($db, -$totalBytes)
            || !global_entry_consume($db, -$totalEntries)
        ) {
            throw new RuntimeException('Global counters could not be updated after the purge.');
        }
        foreach ($accounts as $accountId => $accountBytes) {
            if (!user_quota_consume($db, $accountId, -$accountBytes)) {
                throw new RuntimeException('Account counters could not be updated after the purge.');
            }
        }

        $db->commit();
        $bytes = $totalBytes;
    } catch (Throwable $exception) {
        try {
            $db->rollback();
        } catch (Throwable $rollbackException) {
            opng_log_exception('trash_purge_rollback_failed', $rollbackException);
        }
        throw $exception;
    }

    return ['purged' => $purged, 'bytes' => $bytes];
}

/**
 * Permanently delete one thread now. Used by the administrator trash view.
 */
function trash_purge_thread(mysqli $db, string $entryId): bool
{
    $db->begin_transaction();
    try {
        if (global_quota_lock($db) === null) {
            throw new RuntimeException('Global storage counter is unavailable.');
        }
        $rootId = trash_thread_root_id($db, $entryId);
        if ($rootId === null) {
            $db->rollback();
            return false;
        }

        // Only trashed threads can be purged. Without this guard a crafted
        // request could permanently delete a live paste through the trash view.
        $check = $db->prepare(
            'SELECT deleted_at FROM entries WHERE id = ? AND deleted_at IS NOT NULL'
        );
        if (!$check) {
            throw new RuntimeException('Trash state query could not be prepared.');
        }
        $check->bind_param('s', $rootId);
        if (!$check->execute()) {
            $check->close();
            throw new RuntimeException('Trash state query failed.');
        }
        $checkResult = $check->get_result();
        $trashed = $checkResult ? $checkResult->fetch_assoc() : null;
        if ($checkResult instanceof mysqli_result) {
            $checkResult->free();
        }
        $check->close();
        if (!is_array($trashed)) {
            $db->rollback();
            return false;
        }

        $stmt = $db->prepare(
            'SELECT user_id, COALESCE(SUM(OCTET_LENGTH(text)), 0) AS bytes, COUNT(*) AS entries
             FROM entries WHERE id = ? OR root_id = ? GROUP BY user_id'
        );
        if (!$stmt) {
            throw new RuntimeException('Thread aggregate query could not be prepared.');
        }
        $stmt->bind_param('ss', $rootId, $rootId);
        $stmt->execute();
        $result = $stmt->get_result();
        $totalBytes = 0;
        $totalEntries = 0;
        $accounts = [];
        while ($row = $result->fetch_assoc()) {
            $accountId = $row['user_id'] !== null ? (int) $row['user_id'] : null;
            $accountBytes = (int) ($row['bytes'] ?? 0);
            $totalBytes += $accountBytes;
            $totalEntries += (int) ($row['entries'] ?? 0);
            if ($accountId !== null) {
                $accounts[$accountId] = $accountBytes;
            }
        }
        $result->free();
        $stmt->close();

        trash_lock_accounts($db, $accounts);

        $delete = $db->prepare('DELETE FROM entries WHERE id = ? OR root_id = ?');
        if (!$delete) {
            throw new RuntimeException('Thread delete query could not be prepared.');
        }
        $delete->bind_param('ss', $rootId, $rootId);
        $delete->execute();
        $delete->close();

        if (
            !global_quota_consume($db, -$totalBytes)
            || !global_entry_consume($db, -$totalEntries)
        ) {
            throw new RuntimeException('Global counters could not be updated.');
        }
        foreach ($accounts as $accountId => $accountBytes) {
            if (!user_quota_consume($db, $accountId, -$accountBytes)) {
                throw new RuntimeException('Account counters could not be updated.');
            }
        }

        $db->commit();
        return true;
    } catch (Throwable $exception) {
        try {
            $db->rollback();
        } catch (Throwable $rollbackException) {
            opng_log_exception('trash_purge_thread_rollback_failed', $rollbackException);
        }
        throw $exception;
    }
}

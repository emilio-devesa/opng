<?php

declare(strict_types=1);

// includes/audit_log.php
// Persistent audit trail for sensitive administrative actions (M5).
//
// The table stores the acting account, a short action identifier, an optional
// target description, a keyed hash of the client IP (never the address in
// clear) and the timestamp. Logging is best-effort: a failure is written to
// the application log but never rolls back the administrative action itself.

require_once(__DIR__ . '/ratelimit.php');
require_once(__DIR__ . '/hashing.php');

/**
 * Hash the client IP the same way the rate limiter does.
 *
 * client_ip() honours OPNG_TRUSTED_PROXIES and X-Forwarded-For, so audit
 * events and rate-limit buckets use the same address behind a proxy (SEC-22).
 * The digest is keyed when OPNG_HASH_KEY is configured (SEC-21).
 */
function audit_log_ip_hash(): string
{
    return opng_hash_identifier(client_ip());
}

/**
 * Hash an email address before writing it to the audit target column. The
 * audit log must never store account identifiers in clear text (SEC-14).
 * The digest is keyed when OPNG_HASH_KEY is configured (SEC-21).
 */
function audit_log_email_target(string $email): string
{
    return 'email:' . opng_hash_identifier(strtolower(trim($email)));
}

/**
 * Delete one bounded batch of audit rows older than the configured retention
 * window.
 *
 * Returns the number of deleted rows (0 also covers a failed statement; the
 * failure is logged). A retention of 0 or less disables pruning. The LIMIT
 * keeps an opportunistic call from holding a long table lock inside a request
 * (ROB-20); use audit_log_prune_all() from the CLI tool to drain the backlog.
 */
function audit_log_prune(mysqli $db, ?int $days = null, int $limit = 1000): int
{
    if ($days === null) {
        $raw = getenv('OPNG_AUDIT_RETENTION_DAYS');
        $days = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($days === false) {
            $days = 90;
        }
    }
    if ($days < 1) {
        return 0;
    }

    $limit = max(1, min($limit, 10000));

    try {
        $days = (int) $days;
        $result = $db->query(
            "DELETE FROM audit_log
             WHERE created_at < NOW() - INTERVAL {$days} DAY
             ORDER BY id ASC
             LIMIT {$limit}"
        );
        if ($result === false) {
            error_log('OPNG audit_log.php: audit pruning failed');
            return 0;
        }

        return max(0, $db->affected_rows);
    } catch (mysqli_sql_exception $exception) {
        error_log('OPNG audit_log.php: audit pruning failed: ' . $exception->getMessage());
        return 0;
    }
}

/**
 * Drain every expired audit row in bounded batches. Intended for
 * tools/audit_prune.php and scheduled maintenance, never for a web request.
 */
function audit_log_prune_all(mysqli $db, ?int $days = null, int $limit = 1000): int
{
    $total = 0;
    do {
        $deleted = audit_log_prune($db, $days, $limit);
        $total += $deleted;
    } while ($deleted >= $limit);

    return $total;
}

/**
 * Record one administrative action. Returns false when the row could not be
 * written; callers should continue with the action in that case.
 */
function audit_log_record(mysqli $db, ?int $actorId, string $action, string $target = ''): bool
{
    if (preg_match('/\A[a-z][a-z0-9_.]{0,31}\z/', $action) !== 1) {
        error_log('OPNG audit_log.php: invalid action name');
        return false;
    }

    // Opportunistic retention: one request in a hundred trims one bounded
    // batch so the table does not grow forever on installations without a
    // cron job. Callers run this outside business transactions (ROB-20).
    if (random_int(1, 100) === 1) {
        audit_log_prune($db);
    }

    if (strlen($target) > 255) {
        $target = substr($target, 0, 255);
    }

    $ip_hash = audit_log_ip_hash();
    $stmt = null;

    try {
        $stmt = $db->prepare(
            'INSERT INTO audit_log (actor_id, action, target, ip_hash) VALUES (?, ?, ?, ?)'
        );
        if (!$stmt) {
            error_log('OPNG audit_log.php: ' . $db->error);
            return false;
        }

        $stmt->bind_param('isss', $actorId, $action, $target, $ip_hash);
        if (!$stmt->execute()) {
            error_log('OPNG audit_log.php: ' . $stmt->error);
            return false;
        }

        return true;
    } catch (mysqli_sql_exception $exception) {
        error_log('OPNG audit_log.php: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

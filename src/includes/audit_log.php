<?php
declare(strict_types=1);
// includes/audit_log.php
// Persistent audit trail for sensitive administrative actions (M5).
//
// The table stores the acting account, a short action identifier, an optional
// target description, a SHA-256 hash of the client IP (never the address in
// clear) and the timestamp. Logging is best-effort: a failure is written to
// the application log but never rolls back the administrative action itself.

/**
 * Hash the client IP the same way the rate limiter does.
 */
function audit_log_ip_hash(): string
{
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
    $ip = is_string($remote_addr) && filter_var($remote_addr, FILTER_VALIDATE_IP) !== false
        ? $remote_addr
        : '0.0.0.0';

    return hash('sha256', $ip);
}

/**
 * Hash an email address before writing it to the audit target column. The
 * audit log must never store account identifiers in clear text (SEC-14).
 */
function audit_log_email_target(string $email): string
{
    return 'email:' . hash('sha256', strtolower(trim($email)));
}

/**
 * Delete audit rows older than the configured retention window.
 *
 * Returns the number of deleted rows (0 also covers a failed statement; the
 * failure is logged). A retention of 0 or less disables pruning.
 */
function audit_log_prune(mysqli $db, ?int $days = null): int
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

    try {
        $days = (int) $days;
        $result = $db->query("DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL {$days} DAY");
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
 * Record one administrative action. Returns false when the row could not be
 * written; callers should continue with the action in that case.
 */
function audit_log_record(mysqli $db, ?int $actorId, string $action, string $target = ''): bool
{
    if (preg_match('/\A[a-z][a-z0-9_.]{0,31}\z/', $action) !== 1) {
        error_log('OPNG audit_log.php: invalid action name');
        return false;
    }

    // Opportunistic retention: one request in a hundred trims old rows so the
    // table does not grow forever on installations without a cron job.
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

<?php
/**
 * Verify or rebuild the denormalized storage counters (PERF-10/PERF-17).
 *
 * Usage (from the repository root):
 *   php tools/recount_storage.php
 *   php tools/recount_storage.php --check
 *
 * - CLI only.
 * - Without arguments it rebuilds `storage_counters` (global bytes and entry
 *   count) and every `users.used_bytes` from the authoritative entries table.
 * - `--check` changes nothing: it prints the drift and exits non-zero when the
 *   counters do not match, which makes it suitable for cron/monitoring.
 * - It takes the global counter lock first, matching the application lock
 *   order (storage_counters -> users -> entries).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/includes/user_quota.php';

// PHP 8.5 may report register_argc_argv=Off while the CLI still defines
// $argv; $_SERVER['argv'] is available in every CLI invocation.
$argv = $_SERVER['argv'] ?? [];

$check_only = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--check') {
        $check_only = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php tools/recount_storage.php [--check]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: {$argument}\n");
        fwrite(STDERR, "Usage: php tools/recount_storage.php [--check]\n");
        exit(2);
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
    $db->set_charset('utf8mb4');

    if ($check_only) {
        $actual = $db->query(
            'SELECT COUNT(*) AS total_entries,
                    COALESCE(SUM(OCTET_LENGTH(text)), 0) AS total_bytes
             FROM entries'
        )->fetch_assoc();
        $total_entries = (int) ($actual['total_entries'] ?? 0);
        $total_bytes = (int) ($actual['total_bytes'] ?? 0);

        $global = $db->query(
            "SELECT used_bytes, used_entries
             FROM storage_counters
             WHERE counter_key = 'global'"
        )->fetch_assoc();
        if (!is_array($global)) {
            throw new RuntimeException('The global storage counter row is missing.');
        }

        $drift = false;
        if ((int) $global['used_bytes'] !== $total_bytes) {
            $drift = true;
            fwrite(
                STDERR,
                "DRIFT: storage_counters.used_bytes=" . (int) $global['used_bytes']
                . " expected {$total_bytes}\n"
            );
        }
        if ((int) $global['used_entries'] !== $total_entries) {
            $drift = true;
            fwrite(
                STDERR,
                "DRIFT: storage_counters.used_entries=" . (int) $global['used_entries']
                . " expected {$total_entries}\n"
            );
        }

        $users = $db->query(
            'SELECT u.id, u.username, u.used_bytes, COALESCE(entry_usage.used_bytes, 0) AS expected
             FROM users u
             LEFT JOIN (
                 SELECT user_id, COALESCE(SUM(OCTET_LENGTH(text)), 0) AS used_bytes
                 FROM entries
                 WHERE user_id IS NOT NULL
                 GROUP BY user_id
             ) AS entry_usage ON entry_usage.user_id = u.id
             WHERE u.used_bytes <> COALESCE(entry_usage.used_bytes, 0)
             ORDER BY u.id ASC'
        );
        while ($row = $users->fetch_assoc()) {
            $drift = true;
            fwrite(
                STDERR,
                'DRIFT: user ' . (int) $row['id'] . ' (' . (string) $row['username'] . ')'
                . ' used_bytes=' . (int) $row['used_bytes']
                . ' expected ' . (int) $row['expected'] . "\n"
            );
        }
        $users->free();

        $db->close();
        if ($drift) {
            fwrite(STDERR, "Storage counters differ from the authoritative data.\n");
            exit(1);
        }

        echo "Storage counters match the authoritative data ({$total_entries} entries, {$total_bytes} bytes).\n";
        exit(0);
    }

    $db->begin_transaction();
    // The global row is the write mutex used by every application flow.
    if (global_quota_lock($db) === null) {
        throw new RuntimeException('The global storage counter row is unavailable.');
    }
    if (!user_quota_recount($db)) {
        throw new RuntimeException('Account counters could not be rebuilt.');
    }
    if (!global_quota_recount($db)) {
        throw new RuntimeException('Global counters could not be rebuilt.');
    }
    if (!$db->commit()) {
        throw new RuntimeException('The recount transaction could not be committed.');
    }

    $summary = $db->query(
        'SELECT COUNT(*) AS total_entries,
                COALESCE(SUM(OCTET_LENGTH(text)), 0) AS total_bytes
         FROM entries'
    )->fetch_assoc();
    $db->close();

    echo 'Rebuilt storage counters: ' . (int) ($summary['total_entries'] ?? 0) . ' entries, '
        . (int) ($summary['total_bytes'] ?? 0) . " bytes.\n";
    exit(0);
} catch (Throwable $exception) {
    if (isset($db) && $db instanceof mysqli) {
        try {
            $db->rollback();
        } catch (Throwable) {
            // Best-effort rollback; the original failure is more useful.
        }
        $db->close();
    }
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
}

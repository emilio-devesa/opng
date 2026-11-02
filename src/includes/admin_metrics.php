<?php

/**
 * Admin dashboard metrics with a short-lived MySQL cache.
 *
 * The aggregate queries scan the whole entries table, so they are cached for
 * OPNG_ADMIN_METRICS_TTL seconds in admin_metrics_cache. Read/write failures
 * fall back to computing the metrics directly.
 */
const OPNG_ADMIN_METRICS_TTL = 300;
const OPNG_ADMIN_METRICS_KEY = 'dashboard';

/**
 * @return array{
 *   total_entries: int,
 *   total_users: int,
 *   paste_bytes: int,
 *   languages: list<array{label: string, value: int}>,
 *   active_users: list<array{label: string, value: int}>
 * }
 */
function admin_metrics_load(mysqli $db): array
{
    $cached = admin_metrics_read_cache($db);
    if ($cached !== null) {
        return $cached;
    }

    $metrics = admin_metrics_compute($db);
    admin_metrics_write_cache($db, $metrics);

    return $metrics;
}

/**
 * Remove the cached dashboard after an admin action that changes the counts.
 */
function admin_metrics_invalidate(mysqli $db): void
{
    try {
        $stmt = $db->prepare('DELETE FROM admin_metrics_cache WHERE cache_key = ?');
        if (!$stmt) {
            error_log('OPNG admin_metrics.php: ' . $db->error);
            return;
        }
        $key = OPNG_ADMIN_METRICS_KEY;
        $stmt->bind_param('s', $key);
        if (!$stmt->execute()) {
            error_log('OPNG admin_metrics.php: ' . $stmt->error);
        }
        $stmt->close();
    } catch (mysqli_sql_exception $exception) {
        error_log('OPNG admin_metrics.php: ' . $exception->getMessage());
    }
}

/**
 * @return array{
 *   total_entries: int,
 *   total_users: int,
 *   paste_bytes: int,
 *   languages: list<array{label: string, value: int}>,
 *   active_users: list<array{label: string, value: int}>
 * }|null
 */
function admin_metrics_read_cache(mysqli $db): ?array
{
    try {
        $stmt = $db->prepare(
            'SELECT payload, expires_at FROM admin_metrics_cache WHERE cache_key = ?'
        );
        if (!$stmt) {
            error_log('OPNG admin_metrics.php: ' . $db->error);
            return null;
        }
        $key = OPNG_ADMIN_METRICS_KEY;
        $stmt->bind_param('s', $key);
        if (!$stmt->execute()) {
            error_log('OPNG admin_metrics.php: ' . $stmt->error);
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();

        if ($row === null || (int) $row['expires_at'] < time()) {
            return null;
        }

        $decoded = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

        return admin_metrics_is_valid($decoded) ? $decoded : null;
    } catch (mysqli_sql_exception | JsonException $exception) {
        error_log('OPNG admin_metrics.php: ' . $exception->getMessage());
        return null;
    }
}

/**
 * @param array<string, mixed> $metrics
 */
function admin_metrics_write_cache(mysqli $db, array $metrics): void
{
    try {
        $payload = json_encode($metrics, JSON_THROW_ON_ERROR);
        $expires_at = time() + OPNG_ADMIN_METRICS_TTL;
        $stmt = $db->prepare(
            'INSERT INTO admin_metrics_cache (cache_key, payload, expires_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE payload = ?, expires_at = ?'
        );
        if (!$stmt) {
            error_log('OPNG admin_metrics.php: ' . $db->error);
            return;
        }
        $key = OPNG_ADMIN_METRICS_KEY;
        $stmt->bind_param('ssisi', $key, $payload, $expires_at, $payload, $expires_at);
        if (!$stmt->execute()) {
            error_log('OPNG admin_metrics.php: ' . $stmt->error);
        }
        $stmt->close();
    } catch (mysqli_sql_exception | JsonException $exception) {
        error_log('OPNG admin_metrics.php: ' . $exception->getMessage());
    }
}

function admin_metrics_is_valid(mixed $metrics): bool
{
    if (!is_array($metrics)) {
        return false;
    }
    if (
        !isset($metrics['total_entries'], $metrics['total_users'], $metrics['paste_bytes'])
        || !is_int($metrics['total_entries'])
        || !is_int($metrics['total_users'])
        || !is_int($metrics['paste_bytes'])
        || !isset($metrics['languages'], $metrics['active_users'])
        || !is_array($metrics['languages'])
        || !is_array($metrics['active_users'])
    ) {
        return false;
    }

    foreach (['languages', 'active_users'] as $list_key) {
        foreach ($metrics[$list_key] as $item) {
            if (
                !is_array($item)
                || !isset($item['label'], $item['value'])
                || !is_string($item['label'])
                || !is_int($item['value'])
            ) {
                return false;
            }
        }
    }

    return true;
}

/**
 * @return array{
 *   total_entries: int,
 *   total_users: int,
 *   paste_bytes: int,
 *   languages: list<array{label: string, value: int}>,
 *   active_users: list<array{label: string, value: int}>
 * }
 */
function admin_metrics_compute(mysqli $db): array
{
    $summary_result = $db->query(
        'SELECT
            (SELECT COUNT(*) FROM entries) AS total_entries,
            (SELECT COUNT(*) FROM users) AS total_users,
            (SELECT COALESCE(SUM(OCTET_LENGTH(text)), 0) FROM entries) AS paste_bytes'
    );
    if (!$summary_result) {
        throw new RuntimeException('Admin metrics summary is unavailable.');
    }
    $summary_row = $summary_result->fetch_assoc();
    $summary_result->free();
    if (!is_array($summary_row)) {
        throw new RuntimeException('Admin metrics summary is unavailable.');
    }

    $metrics = [
        'total_entries' => (int) $summary_row['total_entries'],
        'total_users' => (int) $summary_row['total_users'],
        'paste_bytes' => (int) $summary_row['paste_bytes'],
        'languages' => [],
        'active_users' => [],
    ];

    $language_result = $db->query(
        "SELECT COALESCE(NULLIF(language, ''), '') AS language, COUNT(*) AS paste_count
         FROM entries
         GROUP BY COALESCE(NULLIF(language, ''), '')
         ORDER BY paste_count DESC, language ASC"
    );
    if (!$language_result) {
        throw new RuntimeException('Admin language metrics are unavailable.');
    }
    while ($language_row = $language_result->fetch_assoc()) {
        if (!is_string($language_row['language'] ?? null)) {
            $language_result->free();
            throw new RuntimeException('Admin language metrics contain an invalid label.');
        }
        $metrics['languages'][] = [
            'label' => $language_row['language'],
            'value' => (int) $language_row['paste_count'],
        ];
    }
    $language_result->free();

    $active_users_result = $db->query(
        'SELECT users.username, COUNT(*) AS paste_count
         FROM entries
         INNER JOIN users ON entries.user_id = users.id
         GROUP BY users.id, users.username
         ORDER BY paste_count DESC, users.username ASC
         LIMIT 5'
    );
    if (!$active_users_result) {
        throw new RuntimeException('Admin user metrics are unavailable.');
    }
    $top_user_entries = 0;
    while ($active_user_row = $active_users_result->fetch_assoc()) {
        if (!is_string($active_user_row['username'] ?? null)) {
            $active_users_result->free();
            throw new RuntimeException('Admin user metrics contain an invalid label.');
        }
        $paste_count = (int) $active_user_row['paste_count'];
        $metrics['active_users'][] = [
            'label' => $active_user_row['username'],
            'value' => $paste_count,
        ];
        $top_user_entries += $paste_count;
    }
    $active_users_result->free();

    $remaining_user_entries = $metrics['total_entries'] - $top_user_entries;
    if ($remaining_user_entries > 0) {
        $metrics['active_users'][] = [
            'label' => '',
            'value' => $remaining_user_entries,
        ];
    }

    return $metrics;
}

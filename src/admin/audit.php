<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// admin/audit.php
// Audit trail viewer and operational observability panel (10.6).
session_start_secured();
require_once('../includes/admin_guard.php');
require_once('../includes/audit_log.php');
$asset_version = opng_asset_version();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$db = database_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable', '../error.php');
}
$session_user_id = require_admin($db, 'audit.php');

$action_filter = http_string_parameter($_GET, 'action', 32) ?? '';
$actor_filter = http_string_parameter($_GET, 'actor', 50) ?? '';
$from_filter = http_string_parameter($_GET, 'from', 10) ?? '';
$to_filter = http_string_parameter($_GET, 'to', 10) ?? '';
$before = http_positive_integer($_GET['before'] ?? null);

$valid_date = static function (string $value): bool {
    if ($value === '') {
        return true;
    }
    if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts) !== 1) {
        return false;
    }

    return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
};
if (!$valid_date($from_filter) || !$valid_date($to_filter)) {
    opng_error_redirect('error_invalid_search', '../error.php');
}

$conditions = [];
$params = [];
if ($action_filter !== '') {
    $conditions[] = 'audit_log.action LIKE ?';
    $params[] = $action_filter . '%';
}
if ($actor_filter !== '') {
    if (ctype_digit($actor_filter)) {
        $conditions[] = 'audit_log.actor_id = ?';
        $params[] = (int) $actor_filter;
    } else {
        $conditions[] = 'users.username LIKE ?';
        $params[] = $actor_filter . '%';
    }
}
if ($from_filter !== '') {
    $conditions[] = 'audit_log.created_at >= ?';
    $params[] = $from_filter . ' 00:00:00';
}
if ($to_filter !== '') {
    $conditions[] = 'audit_log.created_at < ?';
    $params[] = (new DateTimeImmutable($to_filter))->modify('+1 day')->format('Y-m-d 00:00:00');
}
if ($before !== null) {
    $conditions[] = 'audit_log.id < ?';
    $params[] = $before;
}

$where_sql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
$audit_rows = [];
$has_older = false;
try {
    $stmt = $db->prepare(
        'SELECT audit_log.id, audit_log.actor_id, audit_log.action, audit_log.target,
                audit_log.ip_hash, audit_log.created_at, users.username
         FROM audit_log
         LEFT JOIN users ON users.id = audit_log.actor_id' . $where_sql . '
         ORDER BY audit_log.id DESC
         LIMIT 51'
    );
    if (!$stmt) {
        throw new RuntimeException('Audit query could not be prepared.');
    }
    if ($params !== []) {
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Audit query failed.');
    }
    $result = $stmt->get_result();
    while ($result && ($row = $result->fetch_assoc())) {
        $audit_rows[] = $row;
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();
    $has_older = count($audit_rows) > 50;
    if ($has_older) {
        array_pop($audit_rows);
    }
} catch (mysqli_sql_exception | RuntimeException $exception) {
    opng_log_exception('audit_viewer_query_failed', $exception);
    $db->close();
    opng_error_redirect('error_database_operation', '../error.php');
}

// Operational panel. Every query is bounded and failure of one panel must not
// hide the audit trail, so each block falls back to a safe default.
$rate_summary = [];
$storage = ['used_bytes' => 0, 'used_entries' => 0];
$audit_24h = 0;
$admin_total = 0;
$admin_mfa = 0;
$active_tokens = 0;
try {
    $result = $db->query(
        'SELECT action, COUNT(*) AS buckets, COALESCE(SUM(hits), 0) AS hits
         FROM rate_limits
         WHERE window_start >= UNIX_TIMESTAMP() - 900
         GROUP BY action
         ORDER BY hits DESC
         LIMIT 20'
    );
    while ($result && ($row = $result->fetch_assoc())) {
        $rate_summary[] = [
            'action' => (string) $row['action'],
            'buckets' => (int) $row['buckets'],
            'hits' => (int) $row['hits'],
        ];
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    $result = $db->query(
        "SELECT used_bytes, used_entries FROM storage_counters WHERE counter_key = 'global'"
    );
    if ($result && ($row = $result->fetch_assoc())) {
        $storage = [
            'used_bytes' => (int) ($row['used_bytes'] ?? 0),
            'used_entries' => (int) ($row['used_entries'] ?? 0),
        ];
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    $result = $db->query('SELECT COUNT(*) AS total FROM audit_log WHERE created_at >= NOW() - INTERVAL 1 DAY');
    if ($result && ($row = $result->fetch_assoc())) {
        $audit_24h = (int) ($row['total'] ?? 0);
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    $result = $db->query(
        "SELECT COUNT(*) AS total, COALESCE(SUM(mfa_enabled), 0) AS mfa
         FROM users WHERE role = 'admin'"
    );
    if ($result && ($row = $result->fetch_assoc())) {
        $admin_total = (int) ($row['total'] ?? 0);
        $admin_mfa = (int) ($row['mfa'] ?? 0);
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    $result = $db->query('SELECT COUNT(*) AS total FROM api_tokens WHERE revoked_at IS NULL');
    if ($result && ($row = $result->fetch_assoc())) {
        $active_tokens = (int) ($row['total'] ?? 0);
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
} catch (mysqli_sql_exception | RuntimeException $exception) {
    opng_log_exception('observability_query_failed', $exception);
}

$db->close();

$filter_query = array_filter([
    'action' => $action_filter,
    'actor' => $actor_filter,
    'from' => $from_filter,
    'to' => $to_filter,
], static fn (string $value): bool => $value !== '');
$older_href = '';
if ($has_older) {
    $older_href = 'audit.php?' . http_build_query(
        $filter_query + ['before' => (int) $audit_rows[count($audit_rows) - 1]['id']],
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

$lang_attr = 'en';
$page_title = 'Audit log - Administration';
$page_title_i18n = 'audit_viewer_title';
$base = '../';
require(__DIR__ . '/../includes/layout_header.php');
?>
    <h1 data-i18n="audit_viewer_title">Audit log</h1>
    <p data-i18n="audit_viewer_intro">Sensitive account and administration events. Addresses are stored only as hashes.</p>

    <fieldset class="admin-metrics observability-panel">
        <legend data-i18n="observability_title">Observability</legend>
        <dl class="metrics-grid">
            <div><dt data-i18n="observability_audit_24h">Audit events (24 h)</dt><dd><?php echo $audit_24h; ?></dd></div>
            <div><dt data-i18n="observability_storage">Stored bytes</dt><dd><?php echo number_format($storage['used_bytes']); ?></dd></div>
            <div><dt data-i18n="observability_entries">Stored entries</dt><dd><?php echo number_format($storage['used_entries']); ?></dd></div>
            <div><dt data-i18n="observability_admins_mfa">Administrators with MFA</dt><dd><?php echo $admin_mfa . ' / ' . $admin_total; ?></dd></div>
            <div><dt data-i18n="observability_tokens">Active API tokens</dt><dd><?php echo $active_tokens; ?></dd></div>
        </dl>
        <?php if ($rate_summary !== []): ?>
        <h2 data-i18n="observability_rate_limits">Rate-limit activity (15 min)</h2>
        <div class="table-scroll">
            <table>
                <thead><tr>
                    <th scope="col" data-i18n="observability_action">Action</th>
                    <th scope="col" data-i18n="observability_buckets">Buckets</th>
                    <th scope="col" data-i18n="observability_hits">Hits</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rate_summary as $rate): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($rate['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int) $rate['buckets']; ?></td>
                        <td><?php echo (int) $rate['hits']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p data-i18n="observability_rate_limits_empty">No rate-limit activity in the last 15 minutes.</p>
        <?php endif; ?>
    </fieldset>

    <form method="get" action="audit.php" class="admin-search-form">
        <label for="audit_action" data-i18n="observability_action">Action:</label>
        <input id="audit_action" name="action" type="text" maxlength="32" value="<?php echo htmlspecialchars($action_filter, ENT_QUOTES, 'UTF-8'); ?>">
        <label for="audit_actor" data-i18n="audit_actor">Actor (username or id):</label>
        <input id="audit_actor" name="actor" type="text" maxlength="50" value="<?php echo htmlspecialchars($actor_filter, ENT_QUOTES, 'UTF-8'); ?>">
        <label for="audit_from" data-i18n="audit_from">From:</label>
        <input id="audit_from" name="from" type="date" value="<?php echo htmlspecialchars($from_filter, ENT_QUOTES, 'UTF-8'); ?>">
        <label for="audit_to" data-i18n="audit_to">To:</label>
        <input id="audit_to" name="to" type="date" value="<?php echo htmlspecialchars($to_filter, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" data-i18n="audit_filter">Filter</button>
    </form>

    <?php if ($audit_rows === []): ?>
    <p data-i18n="audit_empty">No audit events match these filters.</p>
    <?php else: ?>
    <div class="table-scroll">
        <table>
            <thead><tr>
                <th scope="col" data-i18n="id">ID</th>
                <th scope="col" data-i18n="audit_actor">Actor</th>
                <th scope="col" data-i18n="observability_action">Action</th>
                <th scope="col" data-i18n="audit_target">Target</th>
                <th scope="col" data-i18n="date">Date</th>
            </tr></thead>
            <tbody>
            <?php foreach ($audit_rows as $row): ?>
                <tr>
                    <td><?php echo (int) $row['id']; ?></td>
                    <td><?php echo $row['username'] !== null ? htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8') : '<span data-i18n="audit_system">System</span>'; ?></td>
                    <td><code><?php echo htmlspecialchars((string) $row['action'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <td><?php echo htmlspecialchars((string) $row['target'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo opng_datetime_html((string) $row['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php if ($older_href !== ''): ?>
    <p><a href="<?php echo htmlspecialchars($older_href, ENT_QUOTES, 'UTF-8'); ?>" data-i18n="next">Next »</a></p>
    <?php endif; ?>
    <p><a href="admin.php" data-i18n="cancel">Cancel</a></p>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>

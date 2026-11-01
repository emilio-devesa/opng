<?php
// (v0.8) Panel de administración.
require("../includes/session.php");
session_start_secured();
require("../includes/csrf.php");
require("../database.php");
require("../includes/http_input.php");
require("../includes/account_role.php");
require("../includes/error_messages.php");
require("../includes/topic_search.php");
require("../includes/pagination_cursor.php");
require("../includes/admin_metrics.php");
require("../includes/user_quota.php");
require("../includes/assets.php");

$asset_version = opng_asset_version();

$session_user_id = http_positive_integer($_SESSION['user_id'] ?? null);
if ($session_user_id === null) {
    opng_error_redirect('error_permission', '../error.php');
}

$db = database_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable', '../error.php');
}

try {
    $current_role = refresh_account_role($db);
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log("OPNG admin.php: " . $exception->getMessage());
    $db->close();
    opng_error_redirect('error_database_operation', '../error.php');
}
if ($current_role !== 'admin') {
    $db->close();
    opng_error_redirect('error_permission', '../error.php');
}

$csrf_token = csrf_token();
$entry_results = [];
$user_results = [];
$entry_search_submitted = isset($_GET['search_entries']);
$user_search_submitted = isset($_GET['search_users']);
$entry_has_older = false;
$entry_has_newer = false;
$user_has_older = false;
$user_has_newer = false;
$entry_search_error = '';
$user_search_error = '';
$entry_topic = http_string_parameter($_GET, 'entry_topic', 255) ?? '';
$entry_author = http_string_parameter($_GET, 'entry_author', 50) ?? '';
$entry_date = http_string_parameter($_GET, 'entry_date', 10) ?? '';
$user_query = http_string_parameter($_GET, 'user_query', 100) ?? '';
$user_date = http_string_parameter($_GET, 'user_date', 10) ?? '';

$entry_cursor_value = http_string_parameter($_GET, 'entry_cursor', 2048, false);
$entry_direction_value = http_string_parameter($_GET, 'entry_direction', 5, false);
$entry_cursor = null;
$entry_direction = 'older';
if ($entry_cursor_value !== null) {
    $entry_cursor = decode_entry_cursor($entry_cursor_value);
    if (
        $entry_cursor === null
        || !is_string($entry_direction_value)
        || !in_array($entry_direction_value, ['older', 'newer'], true)
    ) {
        $entry_search_error = 'admin_search_invalid';
        $entry_cursor = null;
    } else {
        $entry_direction = $entry_direction_value;
    }
}

$user_cursor_value = http_string_parameter($_GET, 'user_cursor', 2048, false);
$user_direction_value = http_string_parameter($_GET, 'user_direction', 5, false);
$user_cursor = null;
$user_direction = 'older';
if ($user_cursor_value !== null) {
    $user_cursor = decode_entry_cursor($user_cursor_value);
    if (
        $user_cursor === null
        || !is_string($user_direction_value)
        || !in_array($user_direction_value, ['older', 'newer'], true)
    ) {
        $user_search_error = 'admin_search_invalid';
        $user_cursor = null;
    } else {
        $user_direction = $user_direction_value;
    }
}

if (
    (array_key_exists('entry_topic', $_GET) && http_string_parameter($_GET, 'entry_topic', 255) === null)
    || (array_key_exists('entry_author', $_GET) && http_string_parameter($_GET, 'entry_author', 50) === null)
    || (array_key_exists('entry_date', $_GET) && http_string_parameter($_GET, 'entry_date', 10) === null)
) {
    $entry_search_error = 'admin_search_invalid';
}
if (
    (array_key_exists('user_query', $_GET) && http_string_parameter($_GET, 'user_query', 100) === null)
    || (array_key_exists('user_date', $_GET) && http_string_parameter($_GET, 'user_date', 10) === null)
) {
    $user_search_error = 'admin_search_invalid';
}

/**
 * Validate a YYYY-MM-DD date and return its half-open SQL range.
 *
 * @return array{start: string, end: string}|null
 */
$date_range = static function (string $date): ?array {
    if ($date === '') {
        return null;
    }

    if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts) !== 1) {
        return null;
    }

    $year = (int) $parts[1];
    $month = (int) $parts[2];
    $day = (int) $parts[3];
    if (!checkdate($month, $day, $year)) {
        return null;
    }

    $start = sprintf('%04d-%02d-%02d', $year, $month, $day);
    $end = (new DateTimeImmutable($start))->modify('+1 day')->format('Y-m-d');

    return ['start' => $start, 'end' => $end];
};

if ($entry_search_submitted && $entry_search_error === '') {
    $filters = [];
    $params = [];
    if ($entry_topic !== '') {
        $topic_expression = topic_search_expression($entry_topic);
        if ($topic_expression === null) {
            $entry_search_error = 'admin_search_invalid';
        } else {
            $filters[] = 'MATCH(entries.topic) AGAINST (? IN BOOLEAN MODE)';
            $params[] = $topic_expression;
        }
    }
    if ($entry_author !== '') {
        $filters[] = "COALESCE(users.username, 'Guest') LIKE ?";
        $params[] = '%' . $entry_author . '%';
    }
    if ($entry_date !== '') {
        $range = $date_range($entry_date);
        if ($range === null) {
            $entry_search_error = 'admin_search_invalid_date';
        } else {
            $filters[] = 'entries.date >= ? AND entries.date < ?';
            $params[] = $range['start'];
            $params[] = $range['end'];
        }
    }
    if ($filters === [] && $entry_search_error === '') {
        $entry_search_error = 'admin_search_required';
    }
    if ($entry_search_error === '') {
        $stmt = null;
        try {
            if ($entry_cursor !== null) {
                $cursor_condition = admin_keyset_condition(
                    'entries.date',
                    'entries.id',
                    $entry_cursor,
                    $entry_direction
                );
                $filters[] = $cursor_condition['sql'];
                array_push($params, ...$cursor_condition['params']);
            }
            $query_order = $entry_direction === 'newer' ? 'ASC' : 'DESC';
            $sql = 'SELECT entries.id, entries.topic, COALESCE(users.username, \'Guest\') AS author, entries.date
                    FROM entries
                    LEFT JOIN users ON entries.user_id = users.id
                    WHERE ' . implode(' AND ', $filters) . "
                    ORDER BY entries.date {$query_order}, entries.id {$query_order}
                    LIMIT 21";
            $stmt = $db->prepare($sql);
            if (!$stmt->execute($params)) {
                throw new RuntimeException('Entry search query failed.');
            }
            $result = $stmt->get_result();
            if (!$result) {
                throw new RuntimeException('Entry search result is unavailable.');
            }
            while ($row = $result->fetch_assoc()) {
                $entry_results[] = $row;
            }
            $result->free();
            $stmt->close();
            $entry_has_more = count($entry_results) > 20;
            if ($entry_has_more) {
                array_pop($entry_results);
            }
            $entry_is_newer = $entry_direction === 'newer';
            if ($entry_is_newer) {
                $entry_results = array_reverse($entry_results);
            }
            $entry_has_older = $entry_results !== [] && ($entry_is_newer || $entry_has_more);
            $entry_has_newer = $entry_results !== [] && (!$entry_is_newer || $entry_has_more);
            if ($entry_results === [] && $entry_cursor !== null) {
                $entry_has_older = $entry_is_newer;
                $entry_has_newer = !$entry_is_newer;
            }
        } catch (mysqli_sql_exception | RuntimeException $exception) {
            error_log("OPNG admin.php: " . $exception->getMessage());
            if ($stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
            $entry_search_error = 'admin_operation_failed';
        }
    }
}

if ($user_search_submitted && $user_search_error === '') {
    $filters = [];
    $params = [];
    if ($user_query !== '') {
        $filters[] = '(users.username LIKE ? OR users.email LIKE ?)';
        $search_pattern = '%' . $user_query . '%';
        $params[] = $search_pattern;
        $params[] = $search_pattern;
    }
    if ($user_date !== '') {
        $range = $date_range($user_date);
        if ($range === null) {
            $user_search_error = 'admin_search_invalid_date';
        } else {
            $filters[] = 'users.created_at >= ? AND users.created_at < ?';
            $params[] = $range['start'];
            $params[] = $range['end'];
        }
    }
    if ($filters === [] && $user_search_error === '') {
        $user_search_error = 'admin_search_required';
    }
    if ($user_search_error === '') {
        $stmt = null;
        try {
            if ($user_cursor !== null) {
                $cursor_condition = admin_keyset_condition(
                    'users.created_at',
                    'users.id',
                    $user_cursor,
                    $user_direction
                );
                $filters[] = $cursor_condition['sql'];
                array_push($params, ...$cursor_condition['params']);
            }
            $query_order = $user_direction === 'newer' ? 'ASC' : 'DESC';
            $sql = 'SELECT users.id, users.username, users.email, users.created_at, users.role
                    FROM users
                    WHERE ' . implode(' AND ', $filters) . "
                    ORDER BY users.created_at {$query_order}, users.id {$query_order}
                    LIMIT 21";
            $stmt = $db->prepare($sql);
            if (!$stmt->execute($params)) {
                throw new RuntimeException('User search query failed.');
            }
            $result = $stmt->get_result();
            if (!$result) {
                throw new RuntimeException('User search result is unavailable.');
            }
            while ($row = $result->fetch_assoc()) {
                $user_results[] = $row;
            }
            $result->free();
            $stmt->close();
            $user_has_more = count($user_results) > 20;
            if ($user_has_more) {
                array_pop($user_results);
            }
            $user_is_newer = $user_direction === 'newer';
            if ($user_is_newer) {
                $user_results = array_reverse($user_results);
            }
            $user_has_older = $user_results !== [] && ($user_is_newer || $user_has_more);
            $user_has_newer = $user_results !== [] && (!$user_is_newer || $user_has_more);
            if ($user_results === [] && $user_cursor !== null) {
                $user_has_older = $user_is_newer;
                $user_has_newer = !$user_is_newer;
            }
        } catch (mysqli_sql_exception | RuntimeException $exception) {
            error_log("OPNG admin.php: " . $exception->getMessage());
            if ($stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
            $user_search_error = 'admin_operation_failed';
        }
    }
}

$metrics_error = false;
$metrics_total_entries = 0;
$metrics_total_users = 0;
$metrics_paste_bytes = 0;
$metrics_languages = [];
$metrics_active_users = [];
try {
    $metrics = admin_metrics_load($db);
    $metrics_total_entries = $metrics['total_entries'];
    $metrics_total_users = $metrics['total_users'];
    $metrics_paste_bytes = $metrics['paste_bytes'];
    $metrics_languages = $metrics['languages'];
    $metrics_active_users = $metrics['active_users'];
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log('OPNG admin.php: metrics query failed: ' . $exception->getMessage());
    $metrics_error = true;
    $metrics_total_entries = 0;
    $metrics_total_users = 0;
    $metrics_paste_bytes = 0;
    $metrics_languages = [];
    $metrics_active_users = [];
}

$global_quota_bytes = global_storage_quota_bytes();
$global_quota_percent = $global_quota_bytes > 0
    ? min(100.0, ($metrics_paste_bytes / $global_quota_bytes) * 100)
    : 0.0;
$global_quota_near = $global_quota_percent >= 90;

$audit_entries = [];
$audit_error = false;
try {
    $audit_result = $db->query(
        "SELECT audit_log.id, audit_log.actor_id, audit_log.action, audit_log.target,
                audit_log.ip_hash, audit_log.created_at, users.username
         FROM audit_log
         LEFT JOIN users ON users.id = audit_log.actor_id
         ORDER BY audit_log.id DESC
         LIMIT 20"
    );
    if (!$audit_result) {
        throw new RuntimeException('Audit log query failed.');
    }
    while ($audit_row = $audit_result->fetch_assoc()) {
        $audit_entries[] = $audit_row;
    }
    $audit_result->free();
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log('OPNG admin.php: audit log query failed: ' . $exception->getMessage());
    $audit_error = true;
}

$db->close();

$entry_page_base = [
    'entry_topic' => $entry_topic,
    'entry_author' => $entry_author,
    'entry_date' => $entry_date,
    'search_entries' => '1',
];
$entry_newer_cursor = $entry_results !== []
    ? encode_entry_cursor([
        'id' => $entry_results[0]['id'],
        'date' => $entry_results[0]['date'],
    ])
    : ($entry_cursor_value ?? '');
$entry_older_cursor = $entry_results !== []
    ? encode_entry_cursor([
        'id' => $entry_results[count($entry_results) - 1]['id'],
        'date' => $entry_results[count($entry_results) - 1]['date'],
    ])
    : ($entry_cursor_value ?? '');
$entry_previous_href = '?' . http_build_query(
    $entry_page_base + ['entry_direction' => 'newer', 'entry_cursor' => $entry_newer_cursor],
    '',
    '&',
    PHP_QUERY_RFC3986
);
$entry_next_href = '?' . http_build_query(
    $entry_page_base + ['entry_direction' => 'older', 'entry_cursor' => $entry_older_cursor],
    '',
    '&',
    PHP_QUERY_RFC3986
);
$user_page_base = [
    'user_query' => $user_query,
    'user_date' => $user_date,
    'search_users' => '1',
];
$user_newer_cursor = $user_results !== []
    ? encode_entry_cursor([
        'id' => (string) $user_results[0]['id'],
        'date' => $user_results[0]['created_at'],
    ])
    : ($user_cursor_value ?? '');
$user_older_cursor = $user_results !== []
    ? encode_entry_cursor([
        'id' => (string) $user_results[count($user_results) - 1]['id'],
        'date' => $user_results[count($user_results) - 1]['created_at'],
    ])
    : ($user_cursor_value ?? '');
$user_previous_href = '?' . http_build_query(
    $user_page_base + ['user_direction' => 'newer', 'user_cursor' => $user_newer_cursor],
    '',
    '&',
    PHP_QUERY_RFC3986
);
$user_next_href = '?' . http_build_query(
    $user_page_base + ['user_direction' => 'older', 'user_cursor' => $user_older_cursor],
    '',
    '&',
    PHP_QUERY_RFC3986
);
$message_key = match (http_string_parameter($_GET, 'msg', 64, false)) {
    'user_deleted_preserved' => 'admin_user_deleted_preserved',
    'user_deleted_entries' => 'admin_user_deleted_entries',
    'user_role_changed' => 'admin_user_role_changed',
    'user_not_found' => 'admin_user_not_found',
    'self_change_denied' => 'admin_self_change_denied',
    'last_admin_protected' => 'admin_last_admin_protected',
    'user_operation_failed' => 'admin_operation_failed',
    default => '',
};
$message_text = match ($message_key) {
    'admin_user_deleted_preserved' => 'User deleted; their pastes were kept anonymously.',
    'admin_user_deleted_entries' => 'User and their pastes deleted.',
    'admin_user_role_changed' => 'User role updated.',
    'admin_user_not_found' => 'The selected user was not found.',
    'admin_self_change_denied' => 'You cannot delete or change your own account here.',
    'admin_last_admin_protected' => 'The last administrator cannot be deleted or demoted.',
    'admin_operation_failed' => 'The requested administration action could not be completed.',
    default => '',
};
$search_error_text = static fn (string $key): string => match ($key) {
    'admin_search_invalid' => 'The search filters are invalid.',
    'admin_search_invalid_date' => 'Choose a valid date.',
    'admin_search_required' => 'Enter at least one search filter.',
    'admin_operation_failed' => 'The requested administration action could not be completed.',
    default => '',
};
$lang_attr = 'en';
$page_title = 'Administration - Open Pastebin NG';
$page_title_i18n = 'admin_title';
$base = '../';
require(__DIR__ . "/../includes/layout_header.php");
?>
    <h2 data-i18n="admin_title">Admin Panel</h2>
    <?php if ($message_key !== ''): ?>
        <p class="flash-message" role="status" data-i18n="<?php echo htmlspecialchars($message_key, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($message_text, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <fieldset class="admin-metrics">
        <legend data-i18n="admin_metrics">Metrics</legend>
        <?php if ($metrics_error): ?>
            <p class="admin-search-error" role="alert" data-i18n="admin_metrics_failed">Metrics could not be loaded.</p>
        <?php else: ?>
            <div class="admin-metrics-summary">
                <article class="admin-metric-card">
                    <h3 data-i18n="admin_metrics_total_pastes">Total pastes</h3>
                    <p><?php echo number_format($metrics_total_entries); ?></p>
                </article>
                <article class="admin-metric-card">
                    <h3 data-i18n="admin_metrics_total_users">Total users</h3>
                    <p><?php echo number_format($metrics_total_users); ?></p>
                </article>
                <article class="admin-metric-card">
                    <h3 data-i18n="admin_metrics_paste_size">Paste text storage</h3>
                    <p><?php echo number_format($metrics_paste_bytes); ?> <span data-i18n="admin_metrics_bytes">bytes</span></p>
                </article>
            </div>
            <div class="admin-metrics-charts">
                <figure class="admin-metric-chart">
                    <figcaption data-i18n="admin_metrics_by_language">Pastes by language</figcaption>
                    <?php if ($metrics_languages === []): ?>
                        <p data-i18n="admin_metrics_no_data">No data available.</p>
                    <?php else: ?>
                        <?php $language_offset = 0.0; ?>
                        <svg class="admin-pie-chart" viewBox="0 0 42 42" aria-hidden="true">
                            <circle class="admin-pie-base" cx="21" cy="21" r="15.9155"></circle>
                            <?php foreach ($metrics_languages as $slice_index => $language_slice): ?>
                                <?php $slice_share = $language_slice['value'] / $metrics_total_entries * 100; ?>
                                <?php $slice_color = ($slice_index % 8) + 1; ?>
                                <circle class="admin-pie-slice admin-pie-color-<?php echo $slice_color; ?>" cx="21" cy="21" r="15.9155"
                                    stroke-dasharray="<?php echo number_format($slice_share, 4, '.', ''); ?> <?php echo number_format(100 - $slice_share, 4, '.', ''); ?>"
                                    stroke-dashoffset="<?php echo number_format(-$language_offset, 4, '.', ''); ?>"></circle>
                                <?php $language_offset += $slice_share; ?>
                            <?php endforeach; ?>
                        </svg>
                        <ul class="admin-pie-legend">
                            <?php foreach ($metrics_languages as $slice_index => $language_slice): ?>
                                <li>
                                    <?php $slice_color = ($slice_index % 8) + 1; ?>
                                    <span class="admin-pie-swatch admin-pie-color-<?php echo $slice_color; ?>" aria-hidden="true"></span>
                                    <span><?php if ($language_slice['label'] === ''): ?><span data-i18n="admin_metrics_unknown">Unknown</span><?php else: ?><?php echo htmlspecialchars($language_slice['label'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></span>
                                    <strong><?php echo number_format($language_slice['value']); ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </figure>
                <figure class="admin-metric-chart">
                    <figcaption data-i18n="admin_metrics_by_user">Pastes by user</figcaption>
                    <?php if ($metrics_active_users === []): ?>
                        <p data-i18n="admin_metrics_no_data">No data available.</p>
                    <?php else: ?>
                        <?php $slice_offset = 0.0; ?>
                        <svg class="admin-pie-chart" viewBox="0 0 42 42" aria-hidden="true">
                            <circle class="admin-pie-base" cx="21" cy="21" r="15.9155"></circle>
                            <?php foreach ($metrics_active_users as $slice_index => $user_slice): ?>
                                <?php $slice_share = $user_slice['value'] / $metrics_total_entries * 100; ?>
                                <?php $slice_color = ($slice_index % 8) + 1; ?>
                                <circle class="admin-pie-slice admin-pie-color-<?php echo $slice_color; ?>" cx="21" cy="21" r="15.9155"
                                    stroke-dasharray="<?php echo number_format($slice_share, 4, '.', ''); ?> <?php echo number_format(100 - $slice_share, 4, '.', ''); ?>"
                                    stroke-dashoffset="<?php echo number_format(-$slice_offset, 4, '.', ''); ?>"></circle>
                                <?php $slice_offset += $slice_share; ?>
                            <?php endforeach; ?>
                        </svg>
                        <ul class="admin-pie-legend">
                            <?php foreach ($metrics_active_users as $slice_index => $user_slice): ?>
                                <li>
                                    <?php $slice_color = ($slice_index % 8) + 1; ?>
                                    <span class="admin-pie-swatch admin-pie-color-<?php echo $slice_color; ?>" aria-hidden="true"></span>
                                    <span><?php if ($user_slice['label'] === ''): ?><span data-i18n="admin_metrics_rest">Rest (including anonymous pastes)</span><?php else: ?><?php echo htmlspecialchars($user_slice['label'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></span>
                                    <strong><?php echo number_format($user_slice['value']); ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </figure>
            </div>
        <?php endif; ?>
    </fieldset>

    <fieldset>
        <legend data-i18n="admin_manage_entries">Manage Entries</legend>
        <form method="get" action="admin.php" class="admin-search-form">
            <label for="entry_topic" data-i18n="admin_entry_title">Title:</label>
            <div class="admin-input-wrap">
                <input id="entry_topic" name="entry_topic" type="text" maxlength="255" value="<?php echo htmlspecialchars($entry_topic, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="admin-field-clear" type="button" aria-label="Clear field" title="Clear field" data-i18n-aria-label="admin_clear_field" data-i18n-title="admin_clear_field" hidden>×</button>
            </div>
            <label for="entry_author" data-i18n="admin_entry_author">Author:</label>
            <div class="admin-input-wrap">
                <input id="entry_author" name="entry_author" type="text" maxlength="50" value="<?php echo htmlspecialchars($entry_author, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="admin-field-clear" type="button" aria-label="Clear field" title="Clear field" data-i18n-aria-label="admin_clear_field" data-i18n-title="admin_clear_field" hidden>×</button>
            </div>
            <label for="entry_date" data-i18n="admin_entry_date">Date:</label>
            <div class="admin-input-wrap">
                <input id="entry_date" name="entry_date" type="date" value="<?php echo htmlspecialchars($entry_date, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="admin-field-clear" type="button" aria-label="Clear field" title="Clear field" data-i18n-aria-label="admin_clear_field" data-i18n-title="admin_clear_field" hidden>×</button>
            </div>
            <button type="submit" name="search_entries" value="1" data-i18n="admin_search_entries">Search entries</button>
        </form>
        <?php if ($entry_search_error !== ''): ?>
            <p class="admin-search-error" role="alert" data-i18n="<?php echo htmlspecialchars($entry_search_error, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($search_error_text($entry_search_error), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php elseif ($entry_search_submitted): ?>
            <?php if ($entry_results === []): ?>
                <p data-i18n="admin_no_entries">No entries found.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr>
                            <th scope="col" data-i18n="topic">Topic</th>
                            <th scope="col" data-i18n="author">Author</th>
                            <th scope="col" data-i18n="date">Date</th>
                            <th scope="col"><span class="visually-hidden" data-i18n="admin_select">Select</span></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($entry_results as $entry): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) ($entry['topic'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($entry['author'] ?? 'Guest'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($entry['date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><button type="button" class="admin-select-result" data-select-entry="<?php echo htmlspecialchars((string) $entry['id'], ENT_QUOTES, 'UTF-8'); ?>" data-i18n="admin_select">Select</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($entry_has_newer || $entry_has_older): ?>
                <nav class="pagination admin-pagination" aria-label="Pagination" data-i18n-aria-label="pagination">
                    <?php if ($entry_has_newer): ?>
                        <a href="<?php echo htmlspecialchars($entry_previous_href, ENT_QUOTES, 'UTF-8'); ?>" data-i18n="previous">« Previous</a>
                    <?php endif; ?>
                    <?php if ($entry_has_older): ?>
                        <a href="<?php echo htmlspecialchars($entry_next_href, ENT_QUOTES, 'UTF-8'); ?>" data-i18n="next">Next »</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
        <hr class="admin-actions-divider">
        <form method="post" action="drop_id.php" class="admin-entry-delete-form" data-confirm-target="drop_confirm">
            <span id="drop_confirm" data-i18n="drop_confirm" hidden>Are you sure you want to drop this entry? This action cannot be undone.</span>
            <label for="input_ID" data-i18n="id_to_remove">ID to remove:</label>
            <div class="admin-input-wrap">
                <input type="text" id="input_ID" name="input_ID" maxlength="255" required>
                <button class="admin-field-clear" type="button" aria-label="Clear field" title="Clear field" data-i18n-aria-label="admin_clear_field" data-i18n-title="admin_clear_field" hidden>×</button>
            </div>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" data-i18n="drop_entry" title="Drop">Drop Entry</button>
        </form>
    </fieldset>

    <fieldset>
        <legend data-i18n="admin_manage_users">Manage Users</legend>
        <form method="get" action="admin.php" class="admin-search-form">
            <label for="user_query" data-i18n="admin_user_query">Username or email:</label>
            <div class="admin-input-wrap">
                <input id="user_query" name="user_query" type="text" maxlength="100" value="<?php echo htmlspecialchars($user_query, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="admin-field-clear" type="button" aria-label="Clear field" title="Clear field" data-i18n-aria-label="admin_clear_field" data-i18n-title="admin_clear_field" hidden>×</button>
            </div>
            <label for="user_date" data-i18n="admin_user_date">Registration date:</label>
            <div class="admin-input-wrap">
                <input id="user_date" name="user_date" type="date" value="<?php echo htmlspecialchars($user_date, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="admin-field-clear" type="button" aria-label="Clear field" title="Clear field" data-i18n-aria-label="admin_clear_field" data-i18n-title="admin_clear_field" hidden>×</button>
            </div>
            <button type="submit" name="search_users" value="1" data-i18n="admin_search_users">Search users</button>
        </form>
        <?php if ($user_search_error !== ''): ?>
            <p class="admin-search-error" role="alert" data-i18n="<?php echo htmlspecialchars($user_search_error, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($search_error_text($user_search_error), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php elseif ($user_search_submitted): ?>
            <?php if ($user_results === []): ?>
                <p data-i18n="admin_no_users">No users found.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr>
                            <th scope="col" data-i18n="username">Username</th>
                            <th scope="col" data-i18n="email">Email</th>
                            <th scope="col" data-i18n="admin_user_date">Registration date:</th>
                            <th scope="col" data-i18n="admin_role">Role</th>
                            <th scope="col"><span class="visually-hidden" data-i18n="admin_select">Select</span></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($user_results as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) $user['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-i18n="<?php echo $user['role'] === 'admin' ? 'role_admin' : 'role_user'; ?>"><?php echo $user['role'] === 'admin' ? 'Administrator' : 'User'; ?></td>
                                <td><button type="button" class="admin-select-result" data-select-user="<?php echo (int) $user['id']; ?>" data-user-role="<?php echo htmlspecialchars((string) $user['role'], ENT_QUOTES, 'UTF-8'); ?>" data-i18n="admin_select">Select</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($user_has_newer || $user_has_older): ?>
                <nav class="pagination admin-pagination" aria-label="Pagination" data-i18n-aria-label="pagination">
                    <?php if ($user_has_newer): ?>
                        <a href="<?php echo htmlspecialchars($user_previous_href, ENT_QUOTES, 'UTF-8'); ?>" data-i18n="previous">« Previous</a>
                    <?php endif; ?>
                    <?php if ($user_has_older): ?>
                        <a href="<?php echo htmlspecialchars($user_next_href, ENT_QUOTES, 'UTF-8'); ?>" data-i18n="next">Next »</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
        <hr class="admin-actions-divider">
        <form method="post" action="user_action.php" class="admin-user-actions">
            <span id="user_action_confirm" data-i18n="admin_user_confirm_action" hidden>Confirm this user-management action. Deleting a user and possibly their pastes is irreversible.</span>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="admin-user-action-primary">
                <label for="admin_record_number" data-i18n="admin_user_id">User ID:</label>
                <div class="admin-input-wrap">
                    <input id="admin_record_number" name="admin_record_number" type="text" inputmode="numeric" pattern="[0-9]+" maxlength="10" autocomplete="new-password" spellcheck="false" required>
                    <button class="admin-field-clear" type="button" aria-label="Clear field" title="Clear field" data-i18n-aria-label="admin_clear_field" data-i18n-title="admin_clear_field" hidden>×</button>
                </div>
                <fieldset class="admin-role-options">
                    <legend data-i18n="admin_new_role">New role:</legend>
                    <label><input type="radio" name="role" value="user" checked required> <span data-i18n="role_user">User</span></label>
                    <label><input type="radio" name="role" value="admin" required> <span data-i18n="role_admin">Administrator</span></label>
                </fieldset>
                <button type="submit" name="action" value="role" data-i18n="admin_change_role">Change role</button>
            </div>
            <fieldset class="admin-choice-group">
                <legend data-i18n="admin_user_pastes">User's pastes</legend>
                <div class="admin-choice-actions">
                    <div class="admin-choice-options">
                        <label><input type="radio" name="delete_mode" value="preserve" checked> <span data-i18n="admin_preserve_pastes">Keep pastes and make them anonymous</span></label>
                        <label><input type="radio" name="delete_mode" value="delete_entries"> <span data-i18n="admin_delete_pastes">Delete pastes with account</span></label>
                    </div>
                    <button type="submit" name="action" value="delete" class="danger" data-i18n="admin_delete_user">Delete user</button>
                </div>
            </fieldset>
        </form>
    </fieldset>

    <fieldset>
        <legend data-i18n="admin_db_actions">Database Actions</legend>
        <p class="form-help admin-storage-quota<?php echo $global_quota_near ? ' admin-storage-quota-warning' : ''; ?>"<?php echo $global_quota_near ? ' role="alert"' : ''; ?>>
            <span data-i18n="admin_storage_quota">Global paste storage</span>:
            <?php echo number_format($metrics_paste_bytes); ?> / <?php echo number_format($global_quota_bytes); ?> bytes
            (<?php echo number_format($global_quota_percent, 1); ?>%)
        </p>
        <?php if ($global_quota_near): ?>
            <p class="admin-search-error" role="alert" data-i18n="admin_storage_quota_warning">The global paste storage quota is almost full. New pastes will be rejected when it is reached.</p>
        <?php endif; ?>
        <p class="form-help admin-backup-warning" data-i18n="admin_backup_warning">The backup contains password hashes and password-reset tokens. Store it securely. Importing it replaces tables in the selected database.</p>
        <div class="admin-db-controls">
        <form action="seed_instructions.php" method="get">
            <button type="submit" data-i18n="admin_seed_button">Load sample data</button>
        </form>
        <form action="backup.php" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" data-i18n="admin_download_backup">Download backup</button>
        </form>
        <form action="empty.php" method="get">
            <button type="submit" data-i18n="empty_pastes" title="Empty">Empty Pastes</button>
        </form>
        <form action="delete_database.php" method="get">
            <button type="submit" class="danger" data-i18n="admin_delete_database">Delete database completely</button>
        </form>
        </div>
    </fieldset>

    <fieldset>
        <legend data-i18n="admin_audit_log">Audit log</legend>
        <?php if ($audit_error): ?>
            <p class="admin-search-error" role="alert" data-i18n="admin_audit_unavailable">The audit log is unavailable until the database is upgraded.</p>
        <?php elseif ($audit_entries === []): ?>
            <p data-i18n="admin_audit_empty">No administrative actions have been recorded yet.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead><tr>
                        <th scope="col" data-i18n="admin_audit_date">Date</th>
                        <th scope="col" data-i18n="admin_audit_actor">Administrator</th>
                        <th scope="col" data-i18n="admin_audit_action">Action</th>
                        <th scope="col" data-i18n="admin_audit_target">Target</th>
                        <th scope="col" data-i18n="admin_audit_ip">IP hash</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($audit_entries as $audit_entry): ?>
                        <?php
                        $audit_action = (string) ($audit_entry['action'] ?? '');
                        $audit_action_key = match ($audit_action) {
                            'entry.delete' => 'audit_entry_delete',
                            'database.empty' => 'audit_database_empty',
                            'user.role' => 'audit_user_role',
                            'user.delete' => 'audit_user_delete',
                            'backup.download' => 'audit_backup_download',
                            default => '',
                        };
                        $audit_action_text = match ($audit_action_key) {
                            'audit_entry_delete' => 'Entry deleted',
                            'audit_database_empty' => 'Database emptied',
                            'audit_user_role' => 'User role changed',
                            'audit_user_delete' => 'User deleted',
                            'audit_backup_download' => 'Backup downloaded',
                            default => $audit_action,
                        };
                        $audit_actor = (string) ($audit_entry['username'] ?? '');
                        if ($audit_actor === '') {
                            $audit_actor = $audit_entry['actor_id'] !== null
                                ? '#' . (int) $audit_entry['actor_id']
                                : 'system';
                        }
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) ($audit_entry['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($audit_actor, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td<?php echo $audit_action_key !== '' ? ' data-i18n="' . htmlspecialchars($audit_action_key, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo htmlspecialchars($audit_action_text, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($audit_entry['target'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><code><?php echo htmlspecialchars(substr((string) ($audit_entry['ip_hash'] ?? ''), 0, 12), ENT_QUOTES, 'UTF-8'); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </fieldset>

    <script src="../assets/js/admin-tools.js?v=<?php echo $asset_version; ?>" defer></script>
<?php require(__DIR__ . "/../includes/layout_footer.php"); ?>

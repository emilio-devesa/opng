<?php
// (v0.8) Listado con paginación por cursor y búsqueda FULLTEXT por tema.
require("includes/session.php");
session_start_secured();
require("includes/db.php");
require("includes/topic_search.php");
require("includes/pagination_cursor.php");
require("includes/http_input.php");

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

// ------------------ Búsqueda ------------------
if (array_key_exists('q', $_GET) && http_string_parameter($_GET, 'q', OPNG_MAX_SEARCH_QUERY_BYTES) === null) {
    header("Location: error.php?msg=" . urlencode("Invalid search query."));
    exit;
}
$q = http_string_parameter($_GET, 'q', OPNG_MAX_SEARCH_QUERY_BYTES) ?? "";
$search_expression = $q === '' ? null : topic_search_expression($q);
if ($q !== '' && $search_expression === null) {
    header("Location: error.php?msg=" . urlencode("Invalid search query."));
    exit;
}

$sort_value = http_string_parameter($_GET, 'sort', 16, false);
if (array_key_exists('sort', $_GET) && ($sort_value === null || entry_sort_expression($sort_value) === null)) {
    header("Location: error.php?msg=" . urlencode("Invalid sort order."));
    exit;
}
$sort = $sort_value ?? 'date';
$default_sort = !array_key_exists('sort', $_GET);
$order_value = http_string_parameter($_GET, 'order', 4, false);
if (
    (array_key_exists('order', $_GET) && $order_value === null)
    || ($order_value !== null && !in_array($order_value, ['asc', 'desc'], true))
) {
    header("Location: error.php?msg=" . urlencode("Invalid sort order."));
    exit;
}
$sort_order = $order_value ?? ($default_sort ? 'desc' : 'asc');

$cursor = null;
$direction = null;
$cursor_token = '';
if (array_key_exists('cursor', $_GET) || array_key_exists('direction', $_GET)) {
    $cursor_value = http_string_parameter($_GET, 'cursor', 2048, false);
    $direction_value = http_string_parameter($_GET, 'direction', 5, false);
    $cursor = decode_listing_cursor($cursor_value);
    if (
        $cursor === null
        || $cursor['sort'] !== $sort
        || $cursor['order'] !== $sort_order
        || !is_string($direction_value)
        || !in_array($direction_value, ['older', 'newer'], true)
    ) {
        header("Location: error.php?msg=" . urlencode("Invalid pagination cursor."));
        exit;
    }
    $direction = $direction_value;
    $cursor_token = encode_listing_cursor($cursor);
}

$db = db_connect();
if (!$db) {
    header("Location: error.php?msg=" . urlencode("Database connection error."));
    exit;
}

$conditions = [];
$params = [];
if ($search_expression !== null) {
    $conditions[] = 'MATCH(entries.topic) AGAINST (? IN BOOLEAN MODE)';
    $params[] = $search_expression;
}
if ($cursor !== null) {
    $cursor_condition = listing_cursor_condition($cursor, $direction);
    $conditions[] = $cursor_condition['sql'];
    array_push($params, ...$cursor_condition['params']);
}

$where_sql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
$sort_expression = entry_sort_expression($sort);
if ($sort_expression === null) {
    throw new LogicException('Validated listing sort has no SQL expression.');
}
$query_order = $sort_order;
if ($direction === 'newer') {
    $query_order = $query_order === 'asc' ? 'desc' : 'asc';
}
$list_sql = "SELECT entries.id, entries.user_id, users.username, entries.topic, entries.language, entries.date
             FROM entries
             LEFT JOIN users ON entries.user_id = users.id"
             . $where_sql .
             " ORDER BY {$sort_expression} {$query_order}, entries.id {$query_order} LIMIT 51";
$entries = [];
try {
    $stmt = $db->prepare($list_sql);
    $executed = $params === [] ? $stmt->execute() : $stmt->execute($params);
    if (!$executed) {
        throw new RuntimeException('Entry listing query failed.');
    }
    $result = $stmt->get_result();
    if (!$result) {
        throw new RuntimeException('Entry listing result is unavailable.');
    }
    while ($row = $result->fetch_assoc()) {
        $entries[] = $row;
    }
    $result->free();
    $stmt->close();
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log('OPNG index.php: ' . $exception->getMessage());
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $db->close();
    header("Location: error.php?msg=" . urlencode("Database operation failed."));
    exit;
}

$has_more = count($entries) > 50;
if ($has_more) {
    array_pop($entries);
}
if ($direction === 'newer') {
    $entries = array_reverse($entries);
}
$has_older = $entries !== [] && ($direction === 'newer' || $has_more);
$has_newer = $entries !== [] && ($direction === 'older' || ($direction === 'newer' && $has_more));
if ($entries === [] && $cursor !== null) {
    $has_older = $direction === 'newer';
    $has_newer = $direction === 'older';
}
$newer_cursor = $entries !== []
    ? encode_listing_cursor([
        'sort' => $sort,
        'order' => $sort_order,
        'value' => entry_listing_sort_value($sort, $entries[0]),
        'id' => $entries[0]['id'],
    ])
    : $cursor_token;
$older_cursor = $entries !== []
    ? encode_listing_cursor([
        'sort' => $sort,
        'order' => $sort_order,
        'value' => entry_listing_sort_value($sort, $entries[count($entries) - 1]),
        'id' => $entries[count($entries) - 1]['id'],
    ])
    : $cursor_token;
$newer_query = ['direction' => 'newer', 'cursor' => $newer_cursor, 'sort' => $sort, 'order' => $sort_order];
$older_query = ['direction' => 'older', 'cursor' => $older_cursor, 'sort' => $sort, 'order' => $sort_order];
if ($q !== '') {
    $newer_query['q'] = $q;
    $older_query['q'] = $q;
}
$newer_href = '?' . http_build_query($newer_query, '', '&', PHP_QUERY_RFC3986);
$older_href = '?' . http_build_query($older_query, '', '&', PHP_QUERY_RFC3986);

$sort_links = [];
foreach (['topic', 'author', 'language', 'date'] as $sortable_column) {
    $next_order = $sort === $sortable_column && $sort_order === 'asc' ? 'desc' : 'asc';
    $sort_query = ['sort' => $sortable_column, 'order' => $next_order];
    if ($q !== '') {
        $sort_query['q'] = $q;
    }
    $sort_links[$sortable_column] = '?' . http_build_query($sort_query, '', '&', PHP_QUERY_RFC3986);
}

// Mensajes de éxito/información que llegan vía ?msg= (p. ej. tras vaciar/borrar)
$flash_message = http_string_parameter($_GET, 'msg', 128) ?? '';
$flash_key = match ($flash_message) {
    'Paste created successfully' => 'paste_created',
    'Paste deleted successfully' => 'paste_deleted',
    'All pastes have been deleted' => 'all_pastes_deleted',
    default => '',
};
$flash = $flash_key !== ''
    ? htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8')
    : '';
$db->close();
$lang_attr = 'en';
$page_title = 'Open Pastebin NG';
$page_title_i18n = 'title_index';
$show_search = true;
?>

<?php require(__DIR__ . "/includes/layout_header.php"); ?>

    <?php if ($flash !== ""): ?>
        <p class="flash-message" role="status"<?php echo $flash_key !== '' ? ' data-i18n="' . htmlspecialchars($flash_key, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo $flash; ?></p>
    <?php endif; ?>

    <div class="list-toolbar">
        <a href="pastebin.php" class="create-link" aria-label="Create new entry" title="Create new entry" data-i18n-aria-label="create_new" data-i18n-title="create_new">➕</a>

        <?php if ($has_newer || $has_older): ?>
        <nav class="pagination" aria-label="Pagination" data-i18n-aria-label="pagination">
            <?php if ($has_newer): ?>
                <a href="<?php echo htmlspecialchars($newer_href, ENT_QUOTES, 'UTF-8'); ?>" data-i18n="previous">« Previous</a>
            <?php endif; ?>
            <?php if ($has_older): ?>
                <a href="<?php echo htmlspecialchars($older_href, ENT_QUOTES, 'UTF-8'); ?>" data-i18n="next">Next »</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    </div>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th scope="col" data-i18n="id">ID</th>
                    <?php foreach (['topic' => 'topic', 'author' => 'author', 'language' => 'language', 'date' => 'date'] as $column => $label_key): ?>
                    <th scope="col" aria-sort="<?php echo $sort === $column ? ($sort_order === 'asc' ? 'ascending' : 'descending') : 'none'; ?>">
                        <a class="sort-link" href="<?php echo htmlspecialchars($sort_links[$column], ENT_QUOTES, 'UTF-8'); ?>" data-i18n="<?php echo $label_key; ?>"><?php echo ucfirst($label_key); ?></a>
                        <?php if ($sort === $column): ?>
                            <span class="sort-indicator" aria-hidden="true"><?php echo $sort_order === 'asc' ? '▲' : '▼'; ?></span>
                        <?php endif; ?>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <a href="view.php?id=<?php echo urlencode($row['id']); ?>">
                            <?php echo htmlspecialchars($row['topic'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </td>
                    <td><?php echo htmlspecialchars($row['username'] ?? "Guest", ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['language'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($entries === [] && $q !== ''): ?>
        <p data-i18n="no_results">No entries match your search.</p>
    <?php endif; ?>

    <?php if ($has_newer || $has_older): ?>
    <nav class="pagination" aria-label="Pagination" data-i18n-aria-label="pagination">
        <?php if ($has_newer): ?>
            <a href="<?php echo htmlspecialchars($newer_href, ENT_QUOTES, 'UTF-8'); ?>" data-i18n="previous">« Previous</a>
        <?php endif; ?>
        <?php if ($has_older): ?>
            <a href="<?php echo htmlspecialchars($older_href, ENT_QUOTES, 'UTF-8'); ?>" data-i18n="next">Next »</a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

<?php require(__DIR__ . "/includes/layout_footer.php"); ?>
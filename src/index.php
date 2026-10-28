<?php
// (v0.8) Listado con paginación por cursor y búsqueda FULLTEXT por tema.
require("includes/session.php");
session_start_secured();
require("includes/db.php");
require("includes/topic_search.php");
require("includes/pagination_cursor.php");

$db = db_connect();
if (!$db) {
    header("Location: error.php?msg=" . urlencode("Database connection error."));
    exit;
}

// ------------------ Búsqueda ------------------
if (isset($_GET['q']) && !is_string($_GET['q'])) {
    header("Location: error.php?msg=" . urlencode("Invalid search query."));
    exit;
}
$q = isset($_GET['q']) ? trim($_GET['q']) : "";
$search_expression = $q === '' ? null : topic_search_expression($q);
if ($q !== '' && $search_expression === null) {
    header("Location: error.php?msg=" . urlencode("Invalid search query."));
    exit;
}

$cursor = null;
$direction = null;
$cursor_token = '';
if (array_key_exists('cursor', $_GET) || array_key_exists('direction', $_GET)) {
    $cursor = decode_entry_cursor($_GET['cursor'] ?? null);
    $direction_value = $_GET['direction'] ?? null;
    if (
        $cursor === null
        || !is_string($direction_value)
        || !in_array($direction_value, ['older', 'newer'], true)
    ) {
        header("Location: error.php?msg=" . urlencode("Invalid pagination cursor."));
        exit;
    }
    $direction = $direction_value;
    $cursor_token = encode_entry_cursor($cursor);
}

$conditions = [];
$params = [];
if ($search_expression !== null) {
    $conditions[] = 'MATCH(entries.topic) AGAINST (? IN BOOLEAN MODE)';
    $params[] = $search_expression;
}
if ($cursor !== null) {
    $cursor_condition = entry_cursor_condition($cursor, $direction);
    $conditions[] = $cursor_condition['sql'];
    array_push($params, ...$cursor_condition['params']);
}

$where_sql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
$order = $direction === 'newer' ? 'ASC' : 'DESC';
$list_sql = "SELECT entries.id, entries.user_id, users.username, entries.topic, entries.language, entries.date
             FROM entries
             LEFT JOIN users ON entries.user_id = users.id"
             . $where_sql .
             " ORDER BY entries.date {$order}, entries.id {$order} LIMIT 51";
$stmt = $db->prepare($list_sql);
if ($params === []) {
    $stmt->execute();
} else {
    $stmt->execute($params);
}
$result = $stmt->get_result();
$entries = [];
while ($row = $result->fetch_assoc()) {
    $entries[] = $row;
}
$stmt->close();

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
    ? encode_entry_cursor(['id' => $entries[0]['id'], 'date' => $entries[0]['date']])
    : $cursor_token;
$older_cursor = $entries !== []
    ? encode_entry_cursor([
        'id' => $entries[count($entries) - 1]['id'],
        'date' => $entries[count($entries) - 1]['date'],
    ])
    : $cursor_token;

// Mensajes de éxito/información que llegan vía ?msg= (p. ej. tras vaciar/borrar)
$flash_message = isset($_GET['msg']) && is_string($_GET['msg']) ? $_GET['msg'] : '';
$flash = $flash_message !== ''
    ? htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8')
    : "";
$flash_key = match ($flash_message) {
    'Paste created successfully' => 'paste_created',
    'Paste deleted successfully' => 'paste_deleted',
    'All pastes have been deleted' => 'all_pastes_deleted',
    default => '',
};
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

        <nav class="pagination" aria-label="Pagination" data-i18n-aria-label="pagination">
            <?php if ($has_newer): ?>
                <a href="?direction=newer&amp;cursor=<?php echo urlencode($newer_cursor); echo $q !== '' ? '&amp;q=' . urlencode($q) : ''; ?>" data-i18n="newer_entries">&laquo; Newer entries</a>
            <?php endif; ?>
            <span data-i18n="<?php echo $q === '' ? 'latest_entries' : 'search_results'; ?>"><?php echo $q === '' ? 'Latest entries' : 'Search results'; ?></span>
            <?php if ($has_older): ?>
                <a href="?direction=older&amp;cursor=<?php echo urlencode($older_cursor); echo $q !== '' ? '&amp;q=' . urlencode($q) : ''; ?>" data-i18n="older_entries">Older entries &raquo;</a>
            <?php endif; ?>
        </nav>
    </div>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th scope="col" data-i18n="id">ID</th>
                    <th scope="col" data-i18n="topic">Topic</th>
                    <th scope="col" data-i18n="author">Author</th>
                    <th scope="col" data-i18n="language">Language</th>
                    <th scope="col" data-i18n="date">Date</th>
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

    <nav class="pagination" aria-label="Pagination" data-i18n-aria-label="pagination">
        <?php if ($has_newer): ?>
            <a href="?direction=newer&amp;cursor=<?php echo urlencode($newer_cursor); echo $q !== '' ? '&amp;q=' . urlencode($q) : ''; ?>" data-i18n="newer_entries">&laquo; Newer entries</a>
        <?php endif; ?>
        <span data-i18n="<?php echo $q === '' ? 'latest_entries' : 'search_results'; ?>"><?php echo $q === '' ? 'Latest entries' : 'Search results'; ?></span>
        <?php if ($has_older): ?>
            <a href="?direction=older&amp;cursor=<?php echo urlencode($older_cursor); echo $q !== '' ? '&amp;q=' . urlencode($q) : ''; ?>" data-i18n="older_entries">Older entries &raquo;</a>
        <?php endif; ?>
    </nav>

<?php require(__DIR__ . "/includes/layout_footer.php"); ?>
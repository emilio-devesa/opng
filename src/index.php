<?php
// (v0.8) Listado con paginación (50/página) y búsqueda por topic (P2/U4).
require("includes/session.php");
session_start_secured();
require("includes/db.php");

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
$where_sql  = "";
$search_param = null;

if ($q !== "") {
    $where_sql = " WHERE entries.topic LIKE ?";
    $search_param = "%" . $q . "%";
}

// ------------------ Paginación (50 por página) ------------------
const ENTRIES_PER_PAGE = 50;

$page = 1;
if (isset($_GET['p'])) {
    if (!is_string($_GET['p']) && !is_int($_GET['p'])) {
        header("Location: error.php?msg=" . urlencode("Invalid page number."));
        exit;
    }
    $page = ctype_digit((string)$_GET['p']) ? max(1, (int)$_GET['p']) : 1;
}

// Total de entradas (con o sin filtro) para calcular páginas
$count_sql = "SELECT COUNT(*) AS total FROM entries" . $where_sql;
$stmt = $db->prepare($count_sql);
if ($search_param !== null) {
    $stmt->bind_param("s", $search_param);
}
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$total_pages = max(1, (int)ceil($total / ENTRIES_PER_PAGE));
if ($page > $total_pages) { $page = $total_pages; }
$offset = ($page - 1) * ENTRIES_PER_PAGE;

// ------------------ Listado ------------------
// (P2) el índice idx_entries_date (ver install.php) hace ordenar por fecha sin filesort
$list_sql = "SELECT entries.id, entries.user_id, users.username, entries.topic, entries.language, entries.date
             FROM entries
             LEFT JOIN users ON entries.user_id = users.id"
             . $where_sql .
             " ORDER BY entries.date DESC LIMIT ? OFFSET ?";
$stmt = $db->prepare($list_sql);
// bind_param() exige variables por referencia: la constante no puede pasarse
// directamente (provocaba "Argument #2 could not be passed by reference").
$limit = ENTRIES_PER_PAGE;
if ($search_param !== null) {
    $stmt->bind_param("sii", $search_param, $limit, $offset);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$result = $stmt->get_result();

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
$show_search = false;
?>

<?php require(__DIR__ . "/includes/layout_header.php"); ?>

    <?php if ($flash !== ""): ?>
        <p class="flash-message" role="status"<?php echo $flash_key !== '' ? ' data-i18n="' . htmlspecialchars($flash_key, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo $flash; ?></p>
    <?php endif; ?>

    <div class="list-toolbar">
        <a href="pastebin.php" data-i18n="create_new" class="create-link">Create New Entry</a>

    <nav class="pagination" aria-label="Pagination" data-i18n-aria-label="pagination">
        <?php if ($page > 1): ?>
            <a href="?p=<?php echo $page - 1; echo $q !== "" ? "&q=" . urlencode($q) : ""; ?>" data-i18n="previous">&laquo; Previous</a>
        <?php endif; ?>
        <span aria-current="page"><span data-i18n="page">Page</span> <?php echo $page; ?> / <?php echo $total_pages; ?></span>
        <?php if ($page < $total_pages): ?>
            <a href="?p=<?php echo $page + 1; echo $q !== "" ? "&q=" . urlencode($q) : ""; ?>" data-i18n="next">Next &raquo;</a>
        <?php endif; ?>
    </nav>

        <form method="get" action="index.php" class="search-form">
            <label class="visually-hidden" for="search-query" data-i18n="search_by_topic">Search by topic</label>
            <input id="search-query" type="text" name="q" placeholder="Search by topic..." data-i18n-placeholder="search_placeholder" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" data-i18n="search">Search</button>
        </form>
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
                <?php while ($row = $result->fetch_assoc()): ?>
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
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total === 0 && $q !== ""): ?>
        <p data-i18n="no_results">No entries match your search.</p>
    <?php endif; ?>

    <nav class="pagination" aria-label="Pagination" data-i18n-aria-label="pagination">
        <?php if ($page > 1): ?>
            <a href="?p=<?php echo $page - 1; echo $q !== "" ? "&q=" . urlencode($q) : ""; ?>" data-i18n="previous">&laquo; Previous</a>
        <?php endif; ?>
        <span aria-current="page"><span data-i18n="page">Page</span> <?php echo $page; ?> / <?php echo $total_pages; ?></span>
        <?php if ($page < $total_pages): ?>
            <a href="?p=<?php echo $page + 1; echo $q !== "" ? "&q=" . urlencode($q) : ""; ?>" data-i18n="next">Next &raquo;</a>
        <?php endif; ?>
    </nav>

    <p class="back-to-top"><a href="#SiteHeader" data-i18n="back_to_top">Back to top</a></p>

<?php require(__DIR__ . "/includes/layout_footer.php"); ?>
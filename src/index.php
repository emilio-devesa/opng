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
$flash = isset($_GET['msg']) && is_string($_GET['msg'])
    ? htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8')
    : "";

$db->close();

$lang_attr = 'en';
$page_title = 'Open Pastebin NG';
$show_search = false;
?>

<?php require(__DIR__ . "/includes/layout_header.php"); ?>

    <?php if ($flash !== ""): ?>
        <p class="flash-message"><?php echo $flash; ?></p>
    <?php endif; ?>

    <div class="list-toolbar">
        <a href="pastebin.php" data-i18n="create_new" class="create-link">Create New Entry</a>

    <p class="pagination">
        <?php if ($page > 1): ?>
            <a href="?p=<?php echo $page - 1; echo $q !== "" ? "&q=" . urlencode($q) : ""; ?>">&laquo; Prev</a>
        <?php endif; ?>
        <span>Page <?php echo $page; ?> / <?php echo $total_pages; ?></span>
        <?php if ($page < $total_pages): ?>
            <a href="?p=<?php echo $page + 1; echo $q !== "" ? "&q=" . urlencode($q) : ""; ?>">Next &raquo;</a>
        <?php endif; ?>
    </p>

        <form method="get" action="index.php" class="search-form">
            <input type="text" name="q" placeholder="Search by topic..." value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" data-i18n="search">Search</button>
        </form>
    </div>
    <table border="1" cellpadding="5">
        <tr>
            <th data-i18n="id">ID</th>
            <th data-i18n="topic">Topic</th>
            <th data-i18n="author">Author</th>
            <th data-i18n="language">Language</th>
            <th data-i18n="date">Date</th>
        </tr>
        <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?php echo htmlspecialchars($row['id']); ?></td>
            <td>
                <a href="view.php?id=<?php echo urlencode($row['id']); ?>">
                    <?php echo htmlspecialchars($row['topic']); ?>
                </a>
            </td>
            <td><?php echo htmlspecialchars($row['username'] ?? "Guest"); ?></td>
            <td><?php echo htmlspecialchars($row['language']); ?></td>
            <td><?php echo htmlspecialchars($row['date']); ?></td>
        </tr>
        <?php endwhile; ?>
    </table>

    <?php if ($total === 0 && $q !== ""): ?>
        <p data-i18n="no_results">No entries match your search.</p>
    <?php endif; ?>

    <p class="pagination">
        <?php if ($page > 1): ?>
            <a href="?p=<?php echo $page - 1; echo $q !== "" ? "&q=" . urlencode($q) : ""; ?>">&laquo; Prev</a>
        <?php endif; ?>
        <span>Page <?php echo $page; ?> / <?php echo $total_pages; ?></span>
        <?php if ($page < $total_pages): ?>
            <a href="?p=<?php echo $page + 1; echo $q !== "" ? "&q=" . urlencode($q) : ""; ?>">Next &raquo;</a>
        <?php endif; ?>
    </p>

    <p class="back-to-top"><a href="#SiteHeader" data-i18n="back_to_top">Back to top</a></p>

<?php require(__DIR__ . "/includes/layout_footer.php"); ?>
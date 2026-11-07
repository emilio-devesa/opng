<?php
/**
 * Thread/history feature contracts (F1).
 *
 * Pure PHP tests for the position, tree and ancestor helpers plus source and
 * catalog contracts. The database integration path lives in
 * tests/database_install_test.php and tests/performance_query_plan_test.php.
 */

require __DIR__ . '/../src/includes/thread.php';

$root = dirname(__DIR__);
$failures = [];

function expect_thread(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

expect_thread(
    OPNG_MAX_THREAD_DEPTH === 32,
    'the maximum thread depth is 32 levels',
    $failures
);
expect_thread(
    OPNG_THREAD_PAGE_SIZE === 500,
    'the history page size is capped at 500 entries',
    $failures
);

$root_position = thread_position(null, 'root-id');
expect_thread(
    $root_position === ['parent_id' => null, 'root_id' => 'root-id', 'depth' => 0],
    'a new root points to itself as the thread root with depth 0',
    $failures
);

$first_reply = thread_position(
    ['id' => 'root-id', 'root_id' => 'root-id', 'depth' => 0],
    'reply-id'
);
expect_thread(
    $first_reply === ['parent_id' => 'root-id', 'root_id' => 'root-id', 'depth' => 1],
    'a direct reply keeps the root and adds one level',
    $failures
);

$deep_reply = thread_position(
    ['id' => 'parent-31', 'root_id' => 'root-id', 'depth' => 31],
    'reply-id'
);
expect_thread(
    $deep_reply === ['parent_id' => 'parent-31', 'root_id' => 'root-id', 'depth' => 32],
    'a reply at depth 31 can still create depth 32',
    $failures
);

$depth_exception = false;
try {
    thread_position(
        ['id' => 'parent-32', 'root_id' => 'root-id', 'depth' => 32],
        'reply-id'
    );
} catch (ThreadDepthException) {
    $depth_exception = true;
}
expect_thread(
    $depth_exception,
    'a reply below depth 32 is rejected with ThreadDepthException',
    $failures
);

$nodes = [
    ['id' => 'root', 'parent_id' => null],
    ['id' => 'a', 'parent_id' => 'root'],
    ['id' => 'b', 'parent_id' => 'a'],
    ['id' => 'c', 'parent_id' => 'root'],
    ['id' => 'orphan', 'parent_id' => 'deleted-parent'],
    ['id' => 'self', 'parent_id' => 'self'],
];
$grouped = thread_group($nodes);
expect_thread(
    $grouped['children']['root'] === ['a', 'c'],
    'the tree groups direct children in chronological input order',
    $failures
);
expect_thread(
    $grouped['children']['a'] === ['b'],
    'the tree keeps nested branches under their direct parent',
    $failures
);
expect_thread(
    $grouped['roots'] === ['root', 'orphan', 'self'],
    'nodes whose parent is outside the page or is themselves become render roots',
    $failures
);
expect_thread(
    count($grouped['by_id']) === count($nodes),
    'every fetched node is addressable by ID',
    $failures
);

$ancestors = thread_ancestor_ids($nodes, 'b');
expect_thread(
    isset($ancestors['a'], $ancestors['root']) && count($ancestors) === 2,
    'ancestors are derived from the parent map without extra queries',
    $failures
);
expect_thread(
    thread_ancestor_ids($nodes, 'root') === [],
    'a root has no ancestors',
    $failures
);
$cyclic = [
    ['id' => 'x', 'parent_id' => 'y'],
    ['id' => 'y', 'parent_id' => 'x'],
];
$cycle_ancestors = thread_ancestor_ids($cyclic, 'x');
expect_thread(
    isset($cycle_ancestors['y']) && count($cycle_ancestors) <= OPNG_MAX_THREAD_DEPTH + 1,
    'ancestor walking terminates on corrupt cyclic data',
    $failures
);

$migration = file_get_contents($root . '/database/migrations/0004_entry_threads.sql');
expect_thread(
    is_string($migration)
    && str_contains($migration, 'ADD COLUMN parent_id VARCHAR(255) NULL')
    && str_contains($migration, 'ADD COLUMN root_id VARCHAR(255) NULL')
    && str_contains($migration, 'ADD COLUMN depth TINYINT UNSIGNED NOT NULL DEFAULT 0')
    && str_contains($migration, 'ADD INDEX idx_entries_parent (parent_id, date, id)')
    && str_contains($migration, 'ADD INDEX idx_entries_root (root_id, date, id)')
    && str_contains($migration, 'FOREIGN KEY (parent_id) REFERENCES entries (id)')
    && str_contains($migration, 'ON DELETE SET NULL')
    && str_contains($migration, 'UPDATE entries SET root_id = id, depth = 0 WHERE root_id IS NULL'),
    'migration 0004 adds the thread columns, indexes, SET NULL foreign key and backfill',
    $failures
);

$schema = file_get_contents($root . '/database/schema.sql');
expect_thread(
    is_string($schema)
    && str_contains($schema, 'parent_id VARCHAR(255) NULL')
    && str_contains($schema, 'root_id VARCHAR(255) NULL')
    && str_contains($schema, 'depth TINYINT UNSIGNED NOT NULL DEFAULT 0')
    && str_contains($schema, 'KEY idx_entries_parent (parent_id, date, id)')
    && str_contains($schema, 'KEY idx_entries_root (root_id, date, id)')
    && str_contains($schema, 'CONSTRAINT fk_entries_parent FOREIGN KEY (parent_id) REFERENCES entries (id) ON DELETE SET NULL'),
    'the canonical schema documents the threaded entries table',
    $failures
);

$submit = file_get_contents($root . '/src/submit.php');
expect_thread(
    is_string($submit)
    && str_contains($submit, "http_string_parameter(\$_POST, 'parent_id', 255, false)")
    && str_contains($submit, 'http_valid_entry_id($parent_id)')
    && str_contains($submit, 'thread_parent_for_reply($db, $parent_id, true)')
    && str_contains($submit, 'thread_position($parent, $id)')
    && str_contains($submit, "'error_thread_too_deep'")
    && str_contains($submit, "'error_entry_not_found'")
    && str_contains($submit, 'INSERT INTO entries (id, parent_id, root_id, depth, user_id, language, text, topic)')
    && str_contains($submit, 'header("Location: view.php?id=" . urlencode($id));'),
    'submit validates the parent, locks it, stores the position and redirects replies to the new entry',
    $failures
);

$view = file_get_contents($root . '/src/view.php');
expect_thread(
    is_string($view)
    && str_contains($view, '$valid_tabs = [\'view\', \'edit\', \'reply\', \'history\']')
    && str_contains($view, "http_string_parameter(\$_GET, 'tab', 8, false)")
    && str_contains($view, 'if ($tab === \'history\' && !$has_relation)')
    && str_contains($view, 'thread_fetch_page($db, $thread_root_id, $thread_cursor)')
    && str_contains($view, 'thread_group($thread_nodes)')
    && str_contains($view, 'thread_ancestor_ids($thread_nodes, $array[\'id\'])')
    && str_contains($view, 'name="parent_id"')
    && str_contains($view, 'class="history-list"')
    && str_contains($view, 'class="thread-graph"')
    && str_contains($view, 'class="thread-tree"')
    && str_contains($view, 'aria-current="page"')
    && str_contains($view, 'opng_datetime_html(')
    && str_contains($view, 'history_deleted_parent')
    && str_contains($view, 'history_deleted_root_notice')
    && str_contains($view, 'history_large_thread')
    && str_contains($view, 'history_load_more')
    && str_contains($view, '<details'),
    'view renders the history tab, chronological list, accessible tree and large-thread controls',
    $failures
);
expect_thread(
    is_string($view)
    && str_contains($view, '<h2 id="history-title"')
    && str_contains($view, '<h3 data-i18n="history_list_title">')
    && substr_count($view, '<h1') === 1,
    'the history section uses h2/h3 headings without adding a second h1',
    $failures
);

$required_i18n_keys = [
    'tab_history',
    'history_title',
    'history_intro',
    'history_current',
    'history_ancestor',
    'history_descendant',
    'history_author',
    'history_date',
    'history_deleted_parent',
    'history_deleted_root_notice',
    'history_branch_graph',
    'history_toggle_branches',
    'history_graph_description',
    'history_thread_root',
    'history_large_thread',
    'history_load_more',
    'history_no_related',
    'history_link_legacy',
    'history_link_legacy_help',
    'history_entries',
    'history_list_title',
    'history_deleted_root',
    'history_guest',
    'history_back',
];
$catalog_keys = [];
foreach (glob($root . '/src/assets/lang/*.json') ?: [] as $catalog_path) {
    $language = basename($catalog_path, '.json');
    $catalog = json_decode((string) file_get_contents($catalog_path), true);
    expect_thread(
        is_array($catalog),
        "translation catalog {$language}.json is a JSON object",
        $failures
    );
    if (!is_array($catalog)) {
        continue;
    }
    $catalog_keys[$language] = array_keys($catalog);
    foreach ($required_i18n_keys as $key) {
        expect_thread(
            isset($catalog[$key]) && is_string($catalog[$key]) && trim($catalog[$key]) !== '',
            "translation {$language}.json:{$key} is present and non-empty",
            $failures
        );
    }
}
expect_thread(
    count($catalog_keys) === 6,
    'all six language catalogs were checked',
    $failures
);
if ($catalog_keys !== []) {
    $reference = $catalog_keys['en'];
    sort($reference);
    foreach ($catalog_keys as $language => $keys) {
        sort($keys);
        expect_thread(
            $keys === $reference,
            "translation keys match the English reference in {$language}.json",
            $failures
        );
    }
}

$error_catalog = file_get_contents($root . '/src/includes/error_messages.php');
expect_thread(
    is_string($error_catalog) && str_contains($error_catalog, "'error_thread_too_deep'"),
    'the depth error is part of the canonical error key list',
    $failures
);

$styles = file_get_contents($root . '/src/main.css');
expect_thread(
    is_string($styles)
    && str_contains($styles, '.history-list')
    && str_contains($styles, '.thread-tree')
    && str_contains($styles, '.thread-node::before')
    && str_contains($styles, '.thread-graph-details summary:focus-visible'),
    'thread list, tree connectors and the details control have styles',
    $failures
);

$legacy_tool = file_get_contents($root . '/tools/link_legacy_replies.php');
expect_thread(
    is_string($legacy_tool)
    && str_contains($legacy_tool, "PHP_SAPI !== 'cli'")
    && str_contains($legacy_tool, "'--dry-run'")
    && str_contains($legacy_tool, "'--apply'")
    && str_contains($legacy_tool, "--apply requires explicit --reply=ID and --parent=ID")
    && str_contains($legacy_tool, 'entry.link_legacy')
    && str_contains($legacy_tool, 'link_legacy_apply_position')
    && str_contains($legacy_tool, 'link_legacy_subtree_metrics')
    && str_contains($legacy_tool, 'UPDATE entries SET parent_id = ?, root_id = ?, depth = ?')
    && !str_contains($legacy_tool, 'WITH RECURSIVE'),
    'the legacy linker is CLI-only, dry-run first, explicit-ID only and MySQL 5.7 compatible',
    $failures
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Thread contracts passed: position, depth limit, tree grouping, migration, UI and i18n.\n";

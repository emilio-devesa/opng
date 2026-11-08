<?php
// Paste view.
//   - "Edit" (real UPDATE): only visible to the author or an admin → update.php (U1)
//   - "Reply" (creates a new RE: paste): always available → submit.php
require("includes/session.php");
session_start_secured();
require("includes/db.php");
require("includes/csrf.php");
require("includes/rules.php");
require("includes/paste_permissions.php");
require("includes/http_input.php");
require("includes/account_role.php");
require("includes/error_messages.php");
require("includes/assets.php");
require("includes/thread.php");
require_once("includes/pagination_cursor.php");
require_once("includes/i18n.php");

$asset_version = opng_asset_version();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

// Get the user role and ID (when authenticated)
$role = $_SESSION['role'] ?? 'guest';
$user_id = $_SESSION['user_id'] ?? null;
$role = is_string($role) ? $role : 'guest';
$user_id = http_positive_integer($user_id);

// Validate and fetch the ID
$id = http_string_parameter($_GET, 'id', 255, false);
if ($id === null || !http_valid_entry_id($id)) {
    opng_error_redirect('error_invalid_id');
}

// Connect to the database
$db = db_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable');
}

// Fetch the entry from the database
$array = null;
try {
    $stmt = $db->prepare(
        "SELECT e.id, e.user_id, e.language, e.text, e.topic, e.date,
                e.parent_id, e.root_id, e.depth,
                EXISTS(SELECT 1 FROM entries c WHERE c.parent_id = e.id) AS has_children
         FROM entries e
         WHERE e.id = ?"
    );
    $stmt->bind_param("s", $id);
    if (!$stmt->execute()) {
        throw new RuntimeException('Entry lookup query failed.');
    }
    $result = $stmt->get_result();
    if (!$result) {
        throw new RuntimeException('Entry lookup result is unavailable.');
    }
    $array = $result->fetch_assoc();
    $result->free();
    $stmt->close();
    $role = refresh_account_role($db) ?? 'guest';
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log('OPNG view.php: ' . $exception->getMessage());
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $db->close();
    opng_error_redirect('error_database_operation');
}

if (!$array) {
    opng_error_redirect('error_entry_not_found');
}

// Fetch and normalize the language
$language = trim($array['language']);

// Keep line counting constant-memory for large, newline-heavy pastes.
$line_count = substr_count($array['text'], "\n") + 1;
$textarea_rows = min(max($line_count, 10), 50);
// Rendering one number per line produces ~8 MB of HTML for a newline-heavy
// 1 MiB paste. Cap the gutter; the code itself is always shown in full.
const OPNG_MAX_RENDERED_LINE_NUMBERS = 5000;
$show_line_numbers = $line_count <= OPNG_MAX_RENDERED_LINE_NUMBERS;
// Preselect the closest current language when editing a paste saved with the
// legacy combined "Java/C/C++" value.
$selected_language = $language === 'Java/C/C++' ? 'C++' : $language;

// Permissions, separating edit (author/admin) from delete (admin or author)
$current_user_id = $user_id;
$owner_id = $array['user_id'] !== null ? (int) $array['user_id'] : null;
$can_edit = paste_owner_can_manage($current_user_id, (string) $role, $owner_id);
$can_delete = $can_edit;

$csrf_token = csrf_token();
$lang_attr = 'en';
$page_title = $array['topic'];
$base = '';
// Messages arriving through ?msg= (for example after editing the paste in update.php)
$flash_message = http_string_parameter($_GET, 'msg', 128) ?? '';

// Real relationship: the entry is a reply or has at least one reply.
// Legacy pastes with "RE: " in the topic do not count until they are linked.
// depth keeps thread membership even when the direct parent has been deleted
// (ON DELETE SET NULL leaves parent_id as NULL).
$has_relation = is_string($array['parent_id'] ?? null)
    || (int) ($array['has_children'] ?? 0) > 0
    || (int) ($array['depth'] ?? 0) > 0
    || (is_string($array['root_id'] ?? null) && $array['root_id'] !== $array['id']);
$thread_root_id = is_string($array['root_id'] ?? null) && $array['root_id'] !== ''
    ? $array['root_id']
    : $array['id'];
$thread_deleted_parent = $array['parent_id'] === null
    && (int) ($array['depth'] ?? 0) > 0;
$thread_nodes = [];
$thread_truncated = false;
$thread_next_cursor = null;
$thread_grouped = ['by_id' => [], 'children' => [], 'roots' => []];
$thread_ancestors = [];
$thread_cursor_value = null;
$thread_root_topic = null;

// View tabs: View / Edit / Reply / History
$tab = http_string_parameter($_GET, 'tab', 8, false);
if (array_key_exists('tab', $_GET) && $tab === null) {
    opng_error_redirect('error_invalid_paste');
}
$tab ??= 'view';
$valid_tabs = ['view', 'edit', 'reply', 'history'];
if (!in_array($tab, $valid_tabs, true)) {
    opng_error_redirect('error_invalid_paste');
}
if ($tab === 'edit' && !$can_edit) { $tab = 'view'; }
if ($tab === 'history' && !$has_relation) { $tab = 'view'; }

$rules = [];
if ($tab === 'edit' || $tab === 'reply') {
    try {
        $rules = load_rules();
    } catch (RuntimeException $exception) {
        opng_error_redirect('error_language_config');
    }
}

if ($tab === 'history') {
    $thread_cursor_value = http_string_parameter($_GET, 'cursor', 2048, false);
    $thread_cursor = null;
    if ($thread_cursor_value !== null) {
        $thread_cursor = decode_entry_cursor($thread_cursor_value);
        if ($thread_cursor === null) {
            $db->close();
            opng_error_redirect('error_invalid_cursor');
        }
    }

    try {
        $thread_page = thread_fetch_page($db, $thread_root_id, $thread_cursor);
        $thread_nodes = $thread_page['nodes'];
        $thread_truncated = $thread_page['truncated'];
        $thread_next_cursor = $thread_page['next_cursor'];
        $thread_grouped = thread_group($thread_nodes);
        $thread_ancestors = thread_ancestor_ids($thread_nodes, $array['id']);

        // Later cursor pages do not contain the root, but the summary must not
        // claim it was deleted. One indexed primary-key lookup resolves it.
        if (
            $thread_cursor !== null
            && !isset($thread_grouped['by_id'][$thread_root_id])
        ) {
            $root_stmt = $db->prepare('SELECT topic FROM entries WHERE id = ?');
            if (!$root_stmt) {
                throw new RuntimeException('Thread root lookup could not be prepared.');
            }
            $root_stmt->bind_param('s', $thread_root_id);
            $root_stmt->execute();
            $root_row = $root_stmt->get_result()->fetch_assoc();
            $root_stmt->close();
            if (is_array($root_row) && is_string($root_row['topic'] ?? null)) {
                $thread_root_topic = $root_row['topic'];
            }
        }
    } catch (mysqli_sql_exception | RuntimeException $exception) {
        error_log('OPNG view.php: ' . $exception->getMessage());
        $db->close();
        opng_error_redirect('error_database_operation');
    }
}

$db->close();

$thread_root_node = $thread_grouped['by_id'][$thread_root_id] ?? null;
// On the first page the root is always present unless it was deleted. Later
// cursor pages legitimately start after the root, so they never raise the
// orphaned-thread notice on their own.
$thread_root_deleted = $thread_nodes !== []
    && $thread_cursor_value === null
    && $thread_root_node === null;
$thread_next_href = $thread_next_cursor !== null
    ? 'view.php?' . http_build_query(
        ['id' => $id, 'tab' => 'history', 'cursor' => $thread_next_cursor],
        '',
        '&',
        PHP_QUERY_RFC3986
    )
    : '';

$highlight_language_class = match (strtolower($language)) {
    'plaintext' => 'language-plaintext',
    'bash' => 'language-bash',
    'c' => 'language-c',
    'c++' => 'language-cpp',
    'java' => 'language-java',
    'pascal' => 'language-pascal',
    'php' => 'language-php',
    'python' => 'language-python',
    'ruby' => 'language-ruby',
    // Legacy value used before the combined rule was split.
    'java/c/c++' => 'language-cpp',
    default => '',
};
$flash_key = match ($flash_message) {
    'Paste updated successfully' => 'paste_updated',
    default => '',
};
$flash = $flash_key !== ''
    ? htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8')
    : '';

require(__DIR__ . "/includes/layout_header.php");
?>
    <?php if ($flash !== ""): ?>
        <p class="flash-message" role="status"<?php echo $flash_key !== '' ? ' data-i18n="' . htmlspecialchars($flash_key, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo $flash; ?></p>
    <?php endif; ?>
    <h1 class="paste-title"><?php echo htmlspecialchars($array['topic'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <nav class="tabs" aria-label="Paste actions" data-i18n-aria-label="paste_navigation">
        <a href="view.php?id=<?php echo urlencode($id); ?>&tab=view" class="tab tab-view <?php echo $tab === 'view' ? 'active' : ''; ?>" data-i18n="tab_view"<?php echo $tab === 'view' ? ' aria-current="page"' : ''; ?>>View</a>
        <?php if ($can_edit): ?>
        <a href="view.php?id=<?php echo urlencode($id); ?>&tab=edit" class="tab tab-edit <?php echo $tab === 'edit' ? 'active' : ''; ?>" data-i18n="tab_edit"<?php echo $tab === 'edit' ? ' aria-current="page"' : ''; ?>>Edit</a>
        <?php endif; ?>
        <a href="view.php?id=<?php echo urlencode($id); ?>&tab=reply" class="tab tab-reply <?php echo $tab === 'reply' ? 'active' : ''; ?>" data-i18n="tab_reply"<?php echo $tab === 'reply' ? ' aria-current="page"' : ''; ?>>Reply</a>
        <?php if ($has_relation): ?>
        <a href="view.php?id=<?php echo urlencode($id); ?>&tab=history" class="tab tab-history <?php echo $tab === 'history' ? 'active' : ''; ?>" data-i18n="tab_history"<?php echo $tab === 'history' ? ' aria-current="page"' : ''; ?>>History</a>
        <?php endif; ?>
    </nav>
    <div class="container">
        <?php if ($tab === 'view'): ?>
        <section class="box" aria-labelledby="paste-code-title">
            <h2 id="paste-code-title" data-i18n="code:">Code:</h2>
            <dl class="paste-meta">
                <div><dt data-i18n="language:">Language:</dt><dd><?php echo htmlspecialchars($language, ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt data-i18n="date">Date:</dt><dd><?php echo opng_datetime_html((string) $array['date']); ?></dd></div>
                <div><dt data-i18n="id">ID:</dt><dd><?php echo htmlspecialchars($array['id'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
            </dl>
            <div class="code-view<?php echo $show_line_numbers ? '' : ' no-line-numbers'; ?>">
                <?php if ($show_line_numbers): ?>
                <pre class="line-numbers" aria-hidden="true"><?php for ($line = 1; $line <= $line_count; $line++) { echo $line . ($line < $line_count ? "\n" : ""); } ?></pre>
                <?php endif; ?>
                <pre class="code-content"><code id="code-block"<?php echo $highlight_language_class !== '' ? ' class="' . htmlspecialchars($highlight_language_class, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo htmlspecialchars($array['text'], ENT_QUOTES, 'UTF-8'); ?></code></pre>
            </div>
            <?php if (!$show_line_numbers): ?>
            <p class="line-numbers-notice" data-i18n="line_numbers_hidden">Line numbers are hidden for very long pastes.</p>
            <?php endif; ?>
            <div class="paste-actions">
                <div class="copy-controls">
                    <button id="copy-button" type="button" data-i18n="copy" class="copy-button">Copy</button>
                    <p id="copy-status" role="status" aria-live="polite" class="copy-status"></p>
                    <span id="copy-copied-message" data-i18n="copied" hidden>Copied!</span>
                    <span id="copy-error-message" data-i18n="copy_error" hidden>Could not copy the code. Check clipboard permissions and try again.</span>
                </div>
                <?php if ($can_delete): ?>
                <form method="post" action="admin/drop_id.php" class="delete-paste-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="input_ID" value="<?php echo htmlspecialchars($array['id'], ENT_QUOTES, 'UTF-8'); ?>">
                    <label class="visually-hidden" for="delete_current_password" data-i18n="reauth_password">Current password:</label>
                    <input id="delete_current_password" type="password" name="current_password" maxlength="72" autocomplete="current-password" required>
                    <label class="confirm-check">
                        <input id="delete_confirm" type="checkbox" name="confirm_delete" value="yes" required>
                        <span data-i18n="delete_confirm">Are you sure you want to delete this paste? This action cannot be undone.</span>
                    </label>
                    <button type="submit" class="danger" aria-label="Delete Paste" title="Delete Paste" data-i18n-aria-label="delete_paste" data-i18n-title="delete_paste">
                        <span aria-hidden="true">🗑️</span>
                        <span class="visually-hidden" data-i18n="delete_paste">Delete Paste</span>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
        <?php if ($tab === 'edit' && $can_edit): ?>
        <section class="box" aria-labelledby="edit-title">
            <h2 id="edit-title" data-i18n="edit:">Edit:</h2>
            <form method="post" action="update.php" class="editor-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="input_ID" value="<?php echo htmlspecialchars($array['id'], ENT_QUOTES, 'UTF-8'); ?>">
                <label for="edit_topic" data-i18n="topic:">Topic:</label>
                <input id="edit_topic" type="text" name="input_topic" maxlength="255" value="<?php echo htmlspecialchars($array['topic'], ENT_QUOTES, 'UTF-8'); ?>" required>
                <label data-i18n="select_language:" for="edit_language">Select language:</label>
                <select id="edit_language" name="input_language" required>
                    <?php foreach ($rules as $rule_name => $rule): ?>
                        <option value="<?php echo htmlspecialchars($rule_name, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($rule_name === $selected_language) ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($rule_name, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="edit_text" data-i18n="text">Enter your text here</label>
                <textarea id="edit_text" name="input_text" maxlength="1048576" rows="<?php echo $textarea_rows; ?>" cols="80" required><?php echo htmlspecialchars($array['text'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                <div class="length-meter" data-length-meter-for="edit_text" data-max-bytes="1048576">
                    <progress class="length-meter-bar" max="1048576" value="0" aria-label="Paste length" data-i18n-aria-label="length_meter_label"></progress>
                    <span class="length-meter-text"><span class="length-meter-value">1.00</span> <span data-i18n="length_mb_remaining">MB remaining</span></span>
                </div>
                <button data-i18n="save" id="edit-save" type="submit">Save changes</button>
            </form>
        </section>
        <?php endif; ?>
        <?php if ($tab === 'reply'): ?>
        <section class="box" aria-labelledby="reply-title">
            <h2 id="reply-title" data-i18n="reply:">Reply (new paste):</h2>
            <form method="post" action="submit.php" class="editor-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="parent_id" value="<?php echo htmlspecialchars($array['id'], ENT_QUOTES, 'UTF-8'); ?>">
                <label for="reply_topic" data-i18n="topic:">Topic:</label>
                <input id="reply_topic" type="text" name="input_topic" maxlength="255" value="RE: <?php echo htmlspecialchars($array['topic'], ENT_QUOTES, 'UTF-8'); ?>" required>
                <label data-i18n="select_language:" for="input_language">Select language:</label>
                <select id="input_language" name="input_language" required>
                    <?php foreach ($rules as $rule_name => $rule): ?>
                        <option value="<?php echo htmlspecialchars($rule_name, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($rule_name === $selected_language) ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($rule_name, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="reply_text" data-i18n="text">Enter your text here</label>
                <textarea id="reply_text" name="input_text" maxlength="1048576" rows="<?php echo $textarea_rows; ?>" cols="80" required></textarea>
                <div class="length-meter" data-length-meter-for="reply_text" data-max-bytes="1048576">
                    <progress class="length-meter-bar" max="1048576" value="0" aria-label="Paste length" data-i18n-aria-label="length_meter_label"></progress>
                    <span class="length-meter-text"><span class="length-meter-value">1.00</span> <span data-i18n="length_mb_remaining">MB remaining</span></span>
                </div>
                <button data-i18n="submit" id="submit" type="submit">Submit</button>
            </form>
        </section>
        <?php endif; ?>
        <?php if ($tab === 'history' && $has_relation): ?>
        <section class="box history-panel" aria-labelledby="history-title">
            <h2 id="history-title" data-i18n="history_title">Thread history</h2>
            <p data-i18n="history_intro">Entries in this thread in chronological order, with their author and date.</p>
            <dl class="history-summary">
                <div>
                    <dt data-i18n="history_entries">Entries:</dt>
                    <dd><?php echo count($thread_nodes); ?><?php echo $thread_truncated ? '+' : ''; ?></dd>
                </div>
                <div>
                    <dt data-i18n="history_thread_root">Thread root:</dt>
                    <dd>
                        <?php if ($thread_root_node !== null): ?>
                        <a href="view.php?id=<?php echo urlencode($thread_root_id); ?>"><?php echo htmlspecialchars((string) $thread_root_node['topic'], ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php elseif ($thread_root_topic !== null): ?>
                        <a href="view.php?id=<?php echo urlencode($thread_root_id); ?>"><?php echo htmlspecialchars($thread_root_topic, ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php else: ?>
                        <span data-i18n="history_deleted_root">Deleted entry</span>
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>
            <?php if ($thread_root_deleted): ?>
            <p class="history-notice" role="status" data-i18n="history_deleted_root_notice">The thread root was deleted; its replies are preserved.</p>
            <?php elseif ($thread_deleted_parent): ?>
            <p class="history-notice" role="status" data-i18n="history_deleted_parent">The original entry was deleted; its replies are preserved.</p>
            <?php endif; ?>
            <?php if ($thread_nodes === []): ?>
            <p data-i18n="history_no_related">No related entries are available.</p>
            <?php else: ?>
            <h3 data-i18n="history_list_title">Chronological list</h3>
            <ol class="history-list">
                <?php foreach ($thread_nodes as $node): ?>
                <?php
                $node_is_current = $node['id'] === $array['id'];
                $node_is_ancestor = isset($thread_ancestors[$node['id']]);
                $node_badge_key = $node_is_current
                    ? 'history_current'
                    : ($node_is_ancestor ? 'history_ancestor' : 'history_descendant');
                $node_badge_text = $node_is_current
                    ? 'Current'
                    : ($node_is_ancestor ? 'Ancestor' : 'Descendant');
                ?>
                <li class="history-item<?php echo $node_is_current ? ' history-current' : ''; ?>">
                    <a href="view.php?id=<?php echo urlencode($node['id']); ?>"<?php echo $node_is_current ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars($node['topic'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <span class="history-badge history-badge-<?php echo htmlspecialchars($node_badge_key, ENT_QUOTES, 'UTF-8'); ?>" data-i18n="<?php echo htmlspecialchars($node_badge_key, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($node_badge_text, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="history-meta">
                        <?php echo opng_datetime_html((string) $node['date']); ?>
                        <span class="history-author">
                            <span data-i18n="history_author">Author:</span>
                            <?php if ($node['username'] !== null): ?>
                            <?php echo htmlspecialchars($node['username'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php else: ?>
                            <span data-i18n="history_guest">Guest</span>
                            <?php endif; ?>
                        </span>
                    </span>
                </li>
                <?php endforeach; ?>
            </ol>
            <figure class="thread-graph">
                <figcaption data-i18n="history_branch_graph">Branch graph</figcaption>
                <p class="thread-graph-description" data-i18n="history_graph_description">Nested branches show how each reply relates to its parent. The current entry is highlighted.</p>
                <?php if ($thread_truncated): ?>
                <details class="thread-graph-details" open>
                    <summary data-i18n="history_toggle_branches">Show or hide branches</summary>
                <?php endif; ?>
                <ul class="thread-tree">
                    <?php
                    $thread_rendered = [];
                    $render_thread_branch = function (string $node_id, int $level) use (
                        &$render_thread_branch,
                        &$thread_rendered,
                        $thread_grouped,
                        $thread_ancestors,
                        $array
                    ): void {
                        if (isset($thread_rendered[$node_id]) || $level > OPNG_MAX_THREAD_DEPTH + 1) {
                            return;
                        }
                        $node = $thread_grouped['by_id'][$node_id] ?? null;
                        if (!is_array($node)) {
                            return;
                        }
                        $thread_rendered[$node_id] = true;
                        $node_is_current = $node_id === $array['id'];
                        $node_is_ancestor = isset($thread_ancestors[$node_id]);
                        $node_badge_key = $node_is_current
                            ? 'history_current'
                            : ($node_is_ancestor ? 'history_ancestor' : 'history_descendant');
                        $node_badge_text = $node_is_current
                            ? 'Current'
                            : ($node_is_ancestor ? 'Ancestor' : 'Descendant');
                        echo '<li class="thread-node' . ($node_is_current ? ' history-current' : '') . '">';
                        echo '<span class="thread-node-main">';
                        echo '<a href="view.php?id=' . urlencode($node_id) . '"' . ($node_is_current ? ' aria-current="page"' : '') . '>'
                            . htmlspecialchars($node['topic'], ENT_QUOTES, 'UTF-8') . '</a>';
                        echo ' <span class="history-badge history-badge-' . htmlspecialchars($node_badge_key, ENT_QUOTES, 'UTF-8')
                            . '" data-i18n="' . htmlspecialchars($node_badge_key, ENT_QUOTES, 'UTF-8') . '">'
                            . htmlspecialchars($node_badge_text, ENT_QUOTES, 'UTF-8') . '</span>';
                        echo '<span class="history-meta">'
                            . opng_datetime_html((string) $node['date']) . ' · '
                            . '<span data-i18n="history_author">Author:</span> ';
                        echo $node['username'] !== null
                            ? htmlspecialchars($node['username'], ENT_QUOTES, 'UTF-8')
                            : '<span data-i18n="history_guest">Guest</span>';
                        echo '</span></span>';

                        $children = $thread_grouped['children'][$node_id] ?? [];
                        if ($children !== []) {
                            echo '<ul class="thread-tree">';
                            foreach ($children as $child_id) {
                                $render_thread_branch((string) $child_id, $level + 1);
                            }
                            echo '</ul>';
                        }
                        echo '</li>';
                    };
                    foreach ($thread_grouped['roots'] as $root_id) {
                        $render_thread_branch((string) $root_id, 0);
                    }
                    ?>
                </ul>
                <?php if ($thread_truncated): ?>
                </details>
                <?php endif; ?>
            </figure>
            <?php endif; ?>
            <?php if ($thread_truncated): ?>
            <p class="history-large-thread" data-i18n="history_large_thread">This thread is very large; only the first page is shown.</p>
            <?php if ($thread_next_href !== ''): ?>
            <p><a class="history-load-more" href="<?php echo htmlspecialchars($thread_next_href, ENT_QUOTES, 'UTF-8'); ?>" data-i18n="history_load_more">Load more</a></p>
            <?php endif; ?>
            <?php endif; ?>
            <p class="history-back">
                <a href="view.php?id=<?php echo urlencode($id); ?>&tab=view" data-i18n="history_back">Back to the entry</a>
            </p>
        </section>
        <?php endif; ?>
    </div>
    <?php if ($tab === 'view'): ?>
    <?php if ($highlight_language_class !== 'language-plaintext'): ?>
    <script src="assets/vendor/highlight.min.js?v=<?php echo $asset_version; ?>" defer></script>
    <?php endif; ?>
    <script src="assets/js/copy.js?v=<?php echo $asset_version; ?>" defer></script>
    <?php endif; ?>
    <script src="assets/js/length-meter.js?v=<?php echo $asset_version; ?>" defer></script>

<?php require(__DIR__ . "/includes/layout_footer.php"); ?>
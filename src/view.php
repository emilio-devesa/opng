<?php
// (v0.8) Vista de un paste.
//   - "Edit" (UPDATE real): solo visible para el autor o un admin → update.php (U1)
//   - "Reply" (crea un paste nuevo tipo RE): siempre disponible → submit.php
require("includes/session.php");
session_start_secured();
require("includes/db.php");
require("includes/csrf.php");
require("includes/rules.php");

// Obtener el rol y el ID del usuario (si está autenticado)
$role = $_SESSION['role'] ?? 'guest';
$user_id = $_SESSION['user_id'] ?? null;

// Verificar y obtener el ID
if (
    !isset($_GET['id'])
    || !is_string($_GET['id'])
    || $_GET['id'] === ''
    || !ctype_alnum($_GET['id'])
) {
    header("Location: error.php?msg=" . urlencode("Invalid ID"));
    exit;
}

$id = trim($_GET['id']);

// Conectar a la base de datos
$db = db_connect();
if (!$db) {
    header("Location: error.php?msg=" . urlencode("Database connection error."));
    exit;
}

// Obtener la entrada desde la base de datos
$stmt = $db->prepare("SELECT id, user_id, language, text, topic, date FROM entries WHERE id = ?");
$stmt->bind_param("s", $id);
$stmt->execute();
$result = $stmt->get_result();
$array = $result->fetch_assoc();
$stmt->close();
$db->close();

if (!$array) {
    header("Location: error.php?msg=" . urlencode("Entry not found"));
    exit;
}

// Obtener y normalizar el lenguaje
$language = trim($array['language']);

// Keep line counting constant-memory for large, newline-heavy pastes.
$line_count = substr_count($array['text'], "\n") + 1;
$textarea_rows = min(max($line_count, 10), 50);

// Permisos, separando edición (autor/admin) de borrado (admin o autor)
$can_edit   = ($role === "admin" || ($user_id !== null && (int)$user_id === (int)$array['user_id'])); // fix R2
$can_delete = $can_edit;

$csrf_token = csrf_token();
$lang_attr = 'en';
$page_title = $array['topic'];
$base = '';
// Mensajes que llegan vía ?msg= (p. ej. tras editar el paste en update.php)
$flash = isset($_GET['msg']) && is_string($_GET['msg'])
    ? htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8')
    : "";

// Pestañas de la vista: Ver / Editar / Responder
$tab = isset($_GET['tab']) && is_string($_GET['tab']) ? $_GET['tab'] : 'view';
$valid_tabs = ['view', 'edit', 'reply'];
if (!in_array($tab, $valid_tabs, true)) { $tab = 'view'; }
if ($tab === 'edit' && !$can_edit) { $tab = 'view'; }

$rules = [];
if ($tab === 'edit' || $tab === 'reply') {
    try {
        $rules = load_rules();
    } catch (RuntimeException $exception) {
        header("Location: error.php?msg=" . urlencode("Language configuration is unavailable."));
        exit;
    }
}

$highlight_language_class = match (strtolower($language)) {
    'plaintext' => 'language-plaintext',
    'bash' => 'language-bash',
    'pascal' => 'language-pascal',
    'python' => 'language-python',
    'php' => 'language-php',
    default => '',
};
$flash_key = match ($_GET['msg'] ?? '') {
    'Paste updated successfully' => 'paste_updated',
    default => '',
};

require(__DIR__ . "/includes/layout_header.php");
?>
    <?php if ($flash !== ""): ?>
        <p class="flash-message" role="status"<?php echo $flash_key !== '' ? ' data-i18n="' . htmlspecialchars($flash_key, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo $flash; ?></p>
    <?php endif; ?>
    <nav class="tabs" aria-label="Paste actions" data-i18n-aria-label="paste_navigation">
        <a href="view.php?id=<?php echo urlencode($id); ?>&tab=view" class="tab tab-view <?php echo $tab === 'view' ? 'active' : ''; ?>" data-i18n="tab_view"<?php echo $tab === 'view' ? ' aria-current="page"' : ''; ?>>View</a>
        <?php if ($can_edit): ?>
        <a href="view.php?id=<?php echo urlencode($id); ?>&tab=edit" class="tab tab-edit <?php echo $tab === 'edit' ? 'active' : ''; ?>" data-i18n="tab_edit"<?php echo $tab === 'edit' ? ' aria-current="page"' : ''; ?>>Edit</a>
        <?php endif; ?>
        <a href="view.php?id=<?php echo urlencode($id); ?>&tab=reply" class="tab tab-reply <?php echo $tab === 'reply' ? 'active' : ''; ?>" data-i18n="tab_reply"<?php echo $tab === 'reply' ? ' aria-current="page"' : ''; ?>>Reply</a>
    </nav>
    <div class="container">
        <?php if ($tab === 'view'): ?>
        <section class="box" aria-labelledby="paste-code-title">
            <h2 id="paste-code-title" data-i18n="code:">Code:</h2>
            <dl class="paste-meta">
                <div><dt data-i18n="language:">Language:</dt><dd><?php echo htmlspecialchars($language, ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt data-i18n="date">Date:</dt><dd><?php echo htmlspecialchars($array['date'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt data-i18n="id">ID:</dt><dd><?php echo htmlspecialchars($array['id'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
            </dl>
            <div class="code-view">
                <pre class="line-numbers" aria-hidden="true"><?php for ($line = 1; $line <= $line_count; $line++) { echo $line . ($line < $line_count ? "\n" : ""); } ?></pre>
                <pre class="code-content"><code id="code-block"<?php echo $highlight_language_class !== '' ? ' class="' . htmlspecialchars($highlight_language_class, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo htmlspecialchars($array['text'], ENT_QUOTES, 'UTF-8'); ?></code></pre>
            </div>
            <div class="copy-controls">
                <button id="copy-button" type="button" data-i18n="copy" class="copy-button">Copy</button>
                <p id="copy-status" role="status" aria-live="polite" class="copy-status"></p>
                <span id="copy-copied-message" data-i18n="copied" hidden>Copied!</span>
                <span id="copy-error-message" data-i18n="copy_error" hidden>Could not copy the code. Check clipboard permissions and try again.</span>
            </div>
            <?php if ($can_delete): ?>
                <form method="post" action="admin/drop_id.php" data-confirm-target="delete_confirm">
                    <span id="delete_confirm" data-i18n="delete_confirm" hidden>Are you sure you want to delete this paste? This action cannot be undone.</span>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="input_ID" value="<?php echo htmlspecialchars($array['id'], ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="danger" data-i18n="delete_paste">Delete Paste</button>
                </form>
            <?php endif; ?>
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
                        <option value="<?php echo htmlspecialchars($rule_name, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($rule_name === $language) ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($rule_name, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="edit_text" data-i18n="text">Enter your text here</label>
                <textarea id="edit_text" name="input_text" maxlength="1048576" rows="<?php echo $textarea_rows; ?>" cols="80" required><?php echo htmlspecialchars($array['text'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                <button data-i18n="save" id="edit-save" type="submit">Save changes</button>
            </form>
        </section>
        <?php endif; ?>
        <?php if ($tab === 'reply'): ?>
        <section class="box" aria-labelledby="reply-title">
            <h2 id="reply-title" data-i18n="reply:">Reply (new paste):</h2>
            <form method="post" action="submit.php" class="editor-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <label for="reply_topic" data-i18n="topic:">Topic:</label>
                <input id="reply_topic" type="text" name="input_topic" maxlength="255" value="RE: <?php echo htmlspecialchars($array['topic'], ENT_QUOTES, 'UTF-8'); ?>" required>
                <label data-i18n="select_language:" for="input_language">Select language:</label>
                <select id="input_language" name="input_language" required>
                    <?php foreach ($rules as $rule_name => $rule): ?>
                        <option value="<?php echo htmlspecialchars($rule_name, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($rule_name === $language) ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($rule_name, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="reply_text" data-i18n="text">Enter your text here</label>
                <textarea id="reply_text" name="input_text" maxlength="1048576" rows="<?php echo $textarea_rows; ?>" cols="80" required><?php echo htmlspecialchars($array['text'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                <button data-i18n="submit" id="submit" type="submit">Submit</button>
            </form>
        </section>
        <?php endif; ?>
    </div>
    <p><a href="index.php" data-i18n="return">Return to Home</a></p>
    <?php if ($tab === 'view'): ?>
    <script src="assets/vendor/highlight.min.js?v=<?php echo filemtime(__DIR__ . '/assets/vendor/highlight.min.js'); ?>" defer></script>
    <script src="assets/js/copy.js?v=<?php echo filemtime(__DIR__ . '/assets/js/copy.js'); ?>" defer></script>
    <?php endif; ?>

<?php require(__DIR__ . "/includes/layout_footer.php"); ?>
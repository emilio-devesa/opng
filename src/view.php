<?php
// (v0.8) Vista de un paste.
//   - "Edit" (UPDATE real): solo visible para el autor o un admin → update.php (U1)
//   - "Reply" (crea un paste nuevo tipo RE): siempre disponible → submit.php
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

$asset_version = opng_asset_version();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

// Obtener el rol y el ID del usuario (si está autenticado)
$role = $_SESSION['role'] ?? 'guest';
$user_id = $_SESSION['user_id'] ?? null;
$role = is_string($role) ? $role : 'guest';
$user_id = http_positive_integer($user_id);

// Verificar y obtener el ID
$id = http_string_parameter($_GET, 'id', 255, false);
if ($id === null || !http_valid_entry_id($id)) {
    opng_error_redirect('error_invalid_id');
}

// Conectar a la base de datos
$db = db_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable');
}

// Obtener la entrada desde la base de datos
$array = null;
try {
    $stmt = $db->prepare("SELECT id, user_id, language, text, topic, date FROM entries WHERE id = ?");
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
    $db->close();
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

// Obtener y normalizar el lenguaje
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

// Permisos, separando edición (autor/admin) de borrado (admin o autor)
$current_user_id = $user_id;
$owner_id = $array['user_id'] !== null ? (int) $array['user_id'] : null;
$can_edit = paste_owner_can_manage($current_user_id, (string) $role, $owner_id);
$can_delete = $can_edit;

$csrf_token = csrf_token();
$lang_attr = 'en';
$page_title = $array['topic'];
$base = '';
// Mensajes que llegan vía ?msg= (p. ej. tras editar el paste en update.php)
$flash_message = http_string_parameter($_GET, 'msg', 128) ?? '';

// Pestañas de la vista: Ver / Editar / Responder
$tab = http_string_parameter($_GET, 'tab', 5, false);
if (array_key_exists('tab', $_GET) && $tab === null) {
    opng_error_redirect('error_invalid_paste');
}
$tab ??= 'view';
$valid_tabs = ['view', 'edit', 'reply'];
if (!in_array($tab, $valid_tabs, true)) {
    opng_error_redirect('error_invalid_paste');
}
if ($tab === 'edit' && !$can_edit) { $tab = 'view'; }

$rules = [];
if ($tab === 'edit' || $tab === 'reply') {
    try {
        $rules = load_rules();
    } catch (RuntimeException $exception) {
        opng_error_redirect('error_language_config');
    }
}

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
                <form method="post" action="admin/drop_id.php" class="delete-paste-form" data-confirm-target="delete_confirm">
                    <span id="delete_confirm" data-i18n="delete_confirm" hidden>Are you sure you want to delete this paste? This action cannot be undone.</span>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="input_ID" value="<?php echo htmlspecialchars($array['id'], ENT_QUOTES, 'UTF-8'); ?>">
                    <label class="visually-hidden" for="delete_current_password" data-i18n="reauth_password">Current password:</label>
                    <input id="delete_current_password" type="password" name="current_password" maxlength="72" autocomplete="current-password" required>
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
                <textarea id="reply_text" name="input_text" maxlength="1048576" rows="<?php echo $textarea_rows; ?>" cols="80" required><?php echo htmlspecialchars($array['text'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                <div class="length-meter" data-length-meter-for="reply_text" data-max-bytes="1048576">
                    <progress class="length-meter-bar" max="1048576" value="0" aria-label="Paste length" data-i18n-aria-label="length_meter_label"></progress>
                    <span class="length-meter-text"><span class="length-meter-value">1.00</span> <span data-i18n="length_mb_remaining">MB remaining</span></span>
                </div>
                <button data-i18n="submit" id="submit" type="submit">Submit</button>
            </form>
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
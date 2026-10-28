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
if (!isset($_GET['id']) || empty($_GET['id'])) {
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

// Contar lineas para el resaltado
$lines = explode("\n", $array['text']);

// Permisos, separando edición (autor/admin) de borrado (admin o autor)
$can_edit   = ($role === "admin" || ($user_id !== null && (int)$user_id === (int)$array['user_id'])); // fix R2
$can_delete = $can_edit;

$csrf_token = csrf_token();
$lang_attr = 'en';
$page_title = $array['topic'];
$base = '';
// Mensajes que llegan vía ?msg= (p. ej. tras editar el paste en update.php)
$flash = isset($_GET['msg']) ? htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8') : "";

// Pestañas de la vista: Ver / Editar / Responder
$tab = $_GET['tab'] ?? 'view';
$valid_tabs = ['view', 'edit', 'reply'];
if (!in_array($tab, $valid_tabs, true)) { $tab = 'view'; }
if ($tab === 'edit' && !$can_edit) { $tab = 'view'; }

require(__DIR__ . "/includes/layout_header.php");
?>
            <?php if ($flash !== ""): ?>
                <p class="flash-message"><?php echo $flash; ?></p>
            <?php endif; ?>
            <div class="tabs">
                <a href="view.php?id=<?php echo urlencode($id); ?>&tab=view" class="tab tab-view <?php echo $tab === 'view' ? 'active' : ''; ?>" data-i18n="tab_view">View</a>
                <?php if ($can_edit): ?>
                <a href="view.php?id=<?php echo urlencode($id); ?>&tab=edit" class="tab tab-edit <?php echo $tab === 'edit' ? 'active' : ''; ?>" data-i18n="tab_edit">Edit</a>
                <?php endif; ?>
                <a href="view.php?id=<?php echo urlencode($id); ?>&tab=reply" class="tab tab-reply <?php echo $tab === 'reply' ? 'active' : ''; ?>" data-i18n="tab_reply">Reply</a>
            </div>
            <div class="container">
                <?php if ($tab === 'view'): ?>
                <div class="box">
                    <h3 data-i18n="code:">Code:</h3>
                    <label data-i18n="language:">Language:</label> <?php echo htmlspecialchars($language); ?><br>
                    <label data-i18n="date">Date:</label> <?php echo htmlspecialchars($array['date']); ?><br>
                    <label data-i18n="id">ID:</label> <?php echo htmlspecialchars($array['id']); ?><br>
                    <table>
                        <tr>
                            <td nowrap align="right">
                                <pre><?php echo implode("\n", range(1, count($lines))); ?></pre>
                            </td>
                            <td nowrap align="left">
                                <div style="position: relative;">
                                    <pre><code id="code-block" class="language-<?php echo htmlspecialchars($language); ?>"><?php echo htmlspecialchars($array['text'], ENT_QUOTES, 'UTF-8'); ?></code></pre>
                                    <button id="copy-button" data-i18n="copy" class="copy-button" onclick="copyToClipboard()">📋 Copy</button>
                                </div>
                            </td>
                        </tr>
                    </table>
                    <br>
                    <?php if ($can_delete): ?>
                        <form method="post" action="admin/drop_id.php">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="input_ID" value="<?php echo htmlspecialchars($array['id']); ?>">
                                                        <button type="submit" data-i18n="delete_paste" onclick="return confirm('Are you sure you want to delete this paste?');">Delete Paste</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($tab === 'edit'): ?>
                <?php if ($can_edit): ?>
                <div class="box">
                    <h3 data-i18n="edit:">Edit:</h3>
                    <form method="post" action="update.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="input_ID" value="<?php echo htmlspecialchars($array['id']); ?>">

                        <label data-i18n="topic:">Topic:</label>
                        <input type="text" name="input_topic" value="<?php echo htmlspecialchars($array['topic'], ENT_QUOTES, 'UTF-8'); ?>"><br>

                        <label data-i18n="select_language:" for="edit_language">Select language:</label>
                        <select id="edit_language" name="input_language" required>
                            <?php foreach (load_rules() as $rule_name => $rule): ?>
                                <option value="<?php echo htmlspecialchars($rule_name); ?>" <?php echo ($rule_name === $language) ? "selected" : ""; ?>>
                                    <?php echo htmlspecialchars($rule_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select><br>
                        <br><br>
                        <textarea name="input_text" rows="<?php echo (max(count($lines), 10)+1); ?>" cols="80"><?php echo htmlspecialchars($array['text'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <br><br>
                        <button data-i18n="save" id="edit-save" type="submit">Save changes</button>
                    </form>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($tab === 'reply'): ?>
                <div class="box">
                    <h3 data-i18n="reply:">Reply (new paste):</h3>
                    <form method="post" action="submit.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                        <label data-i18n="topic:">Topic:</label>
                        <input type="text" name="input_topic" value="RE: <?php echo htmlspecialchars($array['topic']); ?>"><br>

                        <label data-i18n="select_language:" for="input_language">Select language:</label>
                        <select id="input_language" name="input_language" required>
                            <?php foreach (load_rules() as $rule_name => $rule): ?>
                                <option value="<?php echo htmlspecialchars($rule_name); ?>" <?php echo ($rule_name === $language) ? "selected" : ""; ?>>
                                    <?php echo htmlspecialchars($rule_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select><br>
                        <br><br>
                        <textarea name="input_text" rows="<?php echo (max(count($lines), 10)+1); ?>" cols="80"><?php echo htmlspecialchars($array['text'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <br><br>
                        <button data-i18n="submit" id="submit" type="submit">Submit</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <br>
            <a href="index.php" data-i18n="return">Return to Home</a>

<?php require(__DIR__ . "/includes/layout_footer.php"); ?>
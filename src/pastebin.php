<?php
// (v0.8) Formulario de creación. Refactor: session/csrf/rules centralizadas.
require("includes/session.php");
session_start_secured();
require("includes/db.php");
require("includes/csrf.php");
require("includes/rules.php");
require("includes/http_input.php");

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$csrf_token = csrf_token();

// Conexión temprana para fallar rápido si la BD no está disponible
$db = db_connect();
if (!$db) {
    header("Location: error.php?msg=" . urlencode("Database connection error."));
    exit;
}
$db->close();

$rules = [];
try {
    $rules = load_rules();
} catch (RuntimeException $exception) {
    header("Location: error.php?msg=" . urlencode("Language configuration is unavailable."));
    exit;
}

$lang_attr = 'en';
$page_title = 'Open Pastebin NG - Create New Entry';
$page_title_i18n = 'title_pastebin';
$base = '';
require(__DIR__ . "/includes/layout_header.php");
?>
    <h2 data-i18n="create_new">Create New Entry</h2>
    <form method="post" action="submit.php" class="editor-form">
        <!-- Protección CSRF -->
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

        <label data-i18n="topic:" for="input_topic">Topic:</label>
        <input type="text" id="input_topic" name="input_topic" maxlength="255" required>

        <label data-i18n="select_language:" for="input_language">Select Language:</label>
        <select id="input_language" name="input_language" required>
            <?php foreach ($rules as $rule_name => $rule): ?>
                <option value="<?php echo htmlspecialchars($rule_name); ?>">
                    <?php echo htmlspecialchars($rule_name); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label data-i18n="text" for="input_text">Enter your text here</label>
        <textarea id="input_text" name="input_text" rows="25" cols="80" maxlength="1048576" required></textarea>
        <p class="form-help" data-i18n="storage_quota_notice">Normal accounts can store up to 100 MiB across all pastes. Administrators are exempt.</p>
        <button data-i18n="submit" id="submit" type="submit">Submit</button>
    </form>
    <a href="index.php" data-i18n="return">Return to Home</a>

<?php require(__DIR__ . "/includes/layout_footer.php"); ?>

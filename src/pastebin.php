<?php
// (v0.8) Formulario de creación. Refactor: session/csrf/rules centralizadas.
require("includes/session.php");
session_start_secured();
require("includes/db.php");
require("includes/csrf.php");
require("includes/rules.php");
require("includes/http_input.php");
require("includes/error_messages.php");
require("includes/assets.php");

$asset_version = opng_asset_version();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$csrf_token = csrf_token();

// Conexión temprana para fallar rápido si la BD no está disponible
$db = db_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable');
}
$db->close();

$rules = [];
try {
    $rules = load_rules();
} catch (RuntimeException $exception) {
    opng_error_redirect('error_language_config');
}

$is_guest = !isset($_SESSION['user_id']);

$lang_attr = 'en';
$page_title = 'Open Pastebin NG - Create New Entry';
$page_title_i18n = 'title_pastebin';
$base = '';
require(__DIR__ . "/includes/layout_header.php");
?>
    <h1 data-i18n="create_new">Create New Entry</h1>
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
        <div class="length-meter" data-length-meter-for="input_text" data-max-bytes="1048576">
            <progress class="length-meter-bar" max="1048576" value="0" aria-label="Paste length" data-i18n-aria-label="length_meter_label"></progress>
            <span class="length-meter-text"><span class="length-meter-value">1.00</span> <span data-i18n="length_mb_remaining">MB remaining</span></span>
        </div>
        <?php if ($is_guest): ?>
        <p>
            <label for="captcha_answer" data-i18n="captcha_label">Please enter the captcha text:</label>
            <input id="captcha_answer" type="text" name="captcha_answer" maxlength="5" autocomplete="off" required>
            <img src="auth/captcha.php" alt="CAPTCHA" class="captcha-image" data-i18n-alt="captcha_alt">
            <button type="button" class="refresh-captcha" data-i18n="refresh_captcha">🔄 Refresh</button>
        </p>
        <?php endif; ?>
        <p class="form-help" data-i18n="storage_quota_notice">Normal accounts can store up to 100 MiB across all pastes. Administrators are exempt.</p>
        <button data-i18n="submit" id="submit" type="submit">Submit</button>
    </form>
    <script src="assets/js/length-meter.js?v=<?php echo $asset_version; ?>" defer></script>
    <?php if ($is_guest): ?>
    <script src="assets/js/refresh-captcha.js?v=<?php echo $asset_version; ?>"></script>
    <?php endif; ?>
<?php require(__DIR__ . "/includes/layout_footer.php"); ?>

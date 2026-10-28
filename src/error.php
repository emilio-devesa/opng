<?php
$flash = isset($_GET['msg']) && is_string($_GET['msg'])
    ? htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8')
    : "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Error</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <div id="Content">
        <h2 data-i18n="error_title">Error</h2>
        <p><?php echo $flash !== "" ? $flash : "An error occurred."; ?></p>
        <p><a href="index.php" data-i18n="return">Return to Home</a></p>
    </div>
    <br>
    <button id="theme-toggle" data-i18n="dark_mode">Dark Mode</button>
    <script src="assets/js/dark-mode.js"></script>
    <br><br>
    <select id="language-selector">
        <option value="en">🇬🇧 English</option>
        <option value="es">🇪🇸 Español</option>
        <option value="de">🇩🇪 Deutsch</option>
        <option value="fr">🇫🇷 Français</option>
        <option value="pt">🇵🇹 Português</option>
        <option value="zh">🇨🇳 中文</option>
    </select>
    <script src="assets/js/language.js"></script>
</body>
</html>
<?php
// includes/layout_header.php
// Cabecera HTML comun. Variables opcionales antes del include:
//   $page_title (string, texto sin escapar — el include NO escapa el título)
//   $base       (string, prefijo de rutas; '' en paginas de src/, '../' en admin/ y auth/)
//   $show_search (bool, true en index.php para mostrar el formulario de busqueda)
//   $logged_in   (bool, derivado de la sesion; se recomputa aqui por comodidad)
if (!isset($base)) { $base = ''; }
$page_title = $page_title ?? 'Open Pastebin NG';
$logged_in = isset($_SESSION['user_id']);
$show_search = isset($show_search) && $show_search;
$language_catalog_version = 0;
foreach (['en', 'es', 'de', 'fr', 'pt', 'zh'] as $language_code) {
    $catalog_mtime = filemtime(__DIR__ . '/../assets/lang/' . $language_code . '.json');
    if ($catalog_mtime === false) {
        throw new RuntimeException('A language catalog is unavailable.');
    }
    $language_catalog_version = max($language_catalog_version, $catalog_mtime);
}
$page_title_i18n_attribute = '';
if (isset($page_title_i18n) && is_string($page_title_i18n)) {
    $page_title_i18n_attribute = ' data-i18n="' . htmlspecialchars($page_title_i18n, ENT_QUOTES, 'UTF-8') . '"';
}
require_once(__DIR__ . '/csrf.php');
$logout_csrf_token = $logged_in ? csrf_token() : '';
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang_attr ?? 'en', ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($no_referrer)): ?>
    <meta name="referrer" content="no-referrer">
    <?php endif; ?>
    <title<?php echo $page_title_i18n_attribute; ?>><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo $base; ?>main.css?v=<?php echo filemtime(__DIR__ . '/../main.css'); ?>">
</head>
<body>
    <header id="SiteHeader">
        <div class="header-top">
            <h1<?php echo $page_title_i18n_attribute; ?>><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
            <div class="header-actions">
                <div class="header-tools">
                    <button id="theme-toggle" type="button" data-i18n-aria-label="dark_mode" data-i18n-title="dark_mode" aria-label="Dark mode" title="Dark mode">🌙</button>
                    <label class="visually-hidden" for="language-selector" data-i18n="language_selector_label">Select language</label>
                    <select id="language-selector" aria-label="Select language" title="Select language">
                        <option value="en" title="English">🇬🇧</option>
                        <option value="es" title="Español">🇪🇸</option>
                        <option value="de" title="Deutsch">🇩🇪</option>
                        <option value="fr" title="Français">🇫🇷</option>
                        <option value="pt" title="Português">🇵🇹</option>
                        <option value="zh" title="中文">🇨🇳</option>
                    </select>
                </div>
                <?php if ($logged_in && ($_SESSION['role'] ?? '') === 'admin'): ?>
                <nav class="header-nav" aria-label="Account navigation" data-i18n-aria-label="account_navigation">
                    <a href="<?php echo $base; ?>admin/admin.php" aria-label="Admin" title="Admin" data-i18n-aria-label="admin" data-i18n-title="admin">🛠️</a>
                    <form method="post" action="<?php echo $base; ?>auth/logout.php" class="logout-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($logout_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" aria-label="Logout" title="Logout" data-i18n-aria-label="logout" data-i18n-title="logout">🚪</button>
                    </form>
                </nav>
                <?php elseif ($logged_in): ?>
                <nav class="header-nav" aria-label="Account navigation" data-i18n-aria-label="account_navigation">
                    <form method="post" action="<?php echo $base; ?>auth/logout.php" class="logout-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($logout_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" aria-label="Logout" title="Logout" data-i18n-aria-label="logout" data-i18n-title="logout">🚪</button>
                    </form>
                </nav>
                <?php else: ?>
                <nav class="header-nav" aria-label="Account navigation" data-i18n-aria-label="account_navigation">
                    <a href="<?php echo $base; ?>auth/login.php" aria-label="Login" title="Login" data-i18n-aria-label="login" data-i18n-title="login">🔐</a>
                    <a href="<?php echo $base; ?>auth/register.php" aria-label="Register" title="Register" data-i18n-aria-label="register" data-i18n-title="register">📝</a>
                </nav>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($show_search): ?>
        <form method="get" action="index.php" class="search-form header-search">
            <label class="visually-hidden" for="header-search" data-i18n="search_by_topic">Search by topic</label>
            <div class="search-input-wrap">
                <input id="header-search" type="text" name="q" maxlength="255" aria-describedby="header-search-help" placeholder="Search by topic..." data-i18n-placeholder="search_placeholder" value="<?php echo htmlspecialchars($q ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <button class="search-help-trigger" type="button" aria-label="Search help" title="Search help" aria-describedby="header-search-help" data-i18n-aria-label="search_help_label" data-i18n-title="search_help_label">ⓘ</button>
                <span id="header-search-help" class="search-help-tooltip" role="tooltip" data-i18n="search_help">All words must match; word beginnings are supported. Short words or stopwords may be ignored.</span>
            </div>
            <button type="submit" aria-label="Search" title="Search" data-i18n-aria-label="search" data-i18n-title="search">🔎</button>
        </form>
        <?php endif; ?>
    </header>
    <script src="<?php echo $base; ?>assets/js/dark-mode.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/dark-mode.js'); ?>" data-base="<?php echo $base; ?>"></script>
    <script src="<?php echo $base; ?>assets/js/language.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/language.js'); ?>" data-base="<?php echo $base; ?>" data-language-version="<?php echo $language_catalog_version; ?>"></script>
    <script src="<?php echo $base; ?>assets/js/confirm.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/confirm.js'); ?>"></script>
    <div id="Content">

<?php

declare(strict_types=1);

// includes/layout_header.php
// Shared HTML header. Optional variables before the include:
//   $page_title (string, unescaped text — the include does NOT escape the title)
//   $base       (string, path prefix; '' under src/, '../' under admin/ and auth/)
//   $show_search (bool, true in index.php to render the search form)
//   $logged_in   (bool, derived from the session; recomputed here for convenience)
if (!isset($base)) {
    $base = '';
}
$page_title = $page_title ?? 'Open Pastebin NG';
$logged_in = isset($_SESSION['user_id']);
$show_search = isset($show_search) && $show_search;
require_once(__DIR__ . '/assets.php');
$asset_version = opng_asset_version();
$page_title_i18n_attribute = '';
if (isset($page_title_i18n) && is_string($page_title_i18n)) {
    $page_title_i18n_attribute = ' data-i18n="' . htmlspecialchars($page_title_i18n, ENT_QUOTES, 'UTF-8') . '"';
}
require_once(__DIR__ . '/csrf.php');
require_once(__DIR__ . '/i18n.php');
// The locale cookie/Accept-Language wins over page-local hard-coded values so
// every page declares the language the server actually rendered.
$lang_attr = opng_current_locale();
$logout_csrf_token = $logged_in ? csrf_token() : '';
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
// Buffer the document so layout_footer.php can translate data-i18n nodes once,
// before anything reaches the client (UX-04).
ob_start();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang_attr, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($no_referrer)) : ?>
    <meta name="referrer" content="no-referrer">
    <?php endif; ?>
    <title<?php echo $page_title_i18n_attribute; ?>><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo $base; ?>main.css?v=<?php echo $asset_version; ?>">
</head>
<body>
    <a class="skip-link" href="#Content" data-i18n="skip_to_content">Skip to content</a>
    <header id="SiteHeader">
        <div class="header-top">
            <p class="site-name" data-i18n="title_index">Open Pastebin NG</p>
            <div class="header-actions">
                <a class="header-home" href="<?php echo $base; ?>index.php" aria-label="Home" title="Home" data-i18n-aria-label="home" data-i18n-title="home">🏠</a>
                <div class="header-tools">
                    <button id="theme-toggle" type="button" data-i18n-aria-label="dark_mode" data-i18n-title="dark_mode" aria-label="Dark mode" title="Dark mode">🌙</button>
                    <label class="visually-hidden" for="language-selector" data-i18n="language_selector_label">Select language</label>
                    <span class="language-select-wrap">
                        <select id="language-selector">
                            <option value="en" lang="en">🇬🇧 English</option>
                            <option value="es" lang="es">🇪🇸 Español</option>
                            <option value="de" lang="de">🇩🇪 Deutsch</option>
                            <option value="fr" lang="fr">🇫🇷 Français</option>
                            <option value="pt" lang="pt">🇵🇹 Português</option>
                            <option value="zh" lang="zh">🇨🇳 中文</option>
                        </select>
                    </span>
                </div>
                <?php if ($logged_in && ($_SESSION['role'] ?? '') === 'admin') : ?>
                <nav class="header-nav" aria-label="Account navigation" data-i18n-aria-label="account_navigation">
                    <a href="<?php echo $base; ?>admin/admin.php" aria-label="Admin" title="Admin" data-i18n-aria-label="admin" data-i18n-title="admin">🛠️</a>
                    <a href="<?php echo $base; ?>auth/change_password.php" aria-label="Change password" title="Change password" data-i18n-aria-label="change_password" data-i18n-title="change_password">🔑</a>
                    <form method="post" action="<?php echo $base; ?>auth/logout.php" class="logout-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($logout_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" aria-label="Logout" title="Logout" data-i18n-aria-label="logout" data-i18n-title="logout">🚪</button>
                    </form>
                </nav>
                <?php elseif ($logged_in) : ?>
                <nav class="header-nav" aria-label="Account navigation" data-i18n-aria-label="account_navigation">
                    <a href="<?php echo $base; ?>auth/change_password.php" aria-label="Change password" title="Change password" data-i18n-aria-label="change_password" data-i18n-title="change_password">🔑</a>
                    <form method="post" action="<?php echo $base; ?>auth/logout.php" class="logout-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($logout_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" aria-label="Logout" title="Logout" data-i18n-aria-label="logout" data-i18n-title="logout">🚪</button>
                    </form>
                </nav>
                <?php else : ?>
                <nav class="header-nav" aria-label="Account navigation" data-i18n-aria-label="account_navigation">
                    <a href="<?php echo $base; ?>auth/login.php" aria-label="Login" title="Login" data-i18n-aria-label="login" data-i18n-title="login">🔐</a>
                    <a href="<?php echo $base; ?>auth/register.php" aria-label="Register" title="Register" data-i18n-aria-label="register" data-i18n-title="register">📝</a>
                </nav>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($show_search) : ?>
        <form method="get" action="index.php" class="search-form header-search">
            <label class="visually-hidden" for="header-search" data-i18n="search_by_topic">Search by topic</label>
            <div class="search-input-wrap">
                <input id="header-search" type="text" name="q" maxlength="255" aria-describedby="header-search-help" placeholder="Search by topic..." data-i18n-placeholder="search_placeholder" value="<?php echo htmlspecialchars($q ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <button class="search-clear" type="button" aria-label="Clear search" title="Clear search" data-i18n-aria-label="search_clear" data-i18n-title="search_clear"<?php echo empty($q) ? ' hidden' : ''; ?>>×</button>
                <button class="search-help-trigger" type="button" aria-label="Search help" title="Search help" aria-expanded="false" aria-controls="header-search-help" data-i18n-aria-label="search_help_label" data-i18n-title="search_help_label">ⓘ</button>
                <span id="header-search-help" class="search-help-tooltip" role="note" data-i18n="search_help">All words must match; word beginnings are supported. Short words or stopwords may be ignored.</span>
            </div>
            <button type="submit" aria-label="Search" title="Search" data-i18n-aria-label="search" data-i18n-title="search">🔎</button>
        </form>
        <?php endif; ?>
    </header>
    <script src="<?php echo $base; ?>assets/js/dark-mode.js?v=<?php echo $asset_version; ?>" data-base="<?php echo $base; ?>"></script>
    <script src="<?php echo $base; ?>assets/js/language.js?v=<?php echo $asset_version; ?>" data-base="<?php echo $base; ?>" data-language-version="<?php echo $asset_version; ?>"></script>
    <?php if ($show_search) : ?>
    <script src="<?php echo $base; ?>assets/js/search-clear.js?v=<?php echo $asset_version; ?>" defer></script>
    <script src="<?php echo $base; ?>assets/js/search-help.js?v=<?php echo $asset_version; ?>" defer></script>
    <?php endif; ?>
    <main id="Content" tabindex="-1">

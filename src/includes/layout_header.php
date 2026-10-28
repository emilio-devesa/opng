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
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang_attr ?? 'en', ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo $base; ?>main.css">
</head>
<body>
    <header id="SiteHeader">
        <div class="header-top">
            <h1><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
            <div class="header-tools">
                <button id="theme-toggle" data-i18n="dark_mode">Dark Mode</button>
                <select id="language-selector">
                    <option value="en">🇬🇧 English</option>
                    <option value="es">🇪🇸 Español</option>
                    <option value="de">🇩🇪 Deutsch</option>
                    <option value="fr">🇫🇷 Français</option>
                    <option value="pt">🇵🇹 Português</option>
                    <option value="zh">🇨🇳 中文</option>
                </select>
            </div>
        </div>
        <?php if ($show_search): ?>
        <form method="get" action="index.php" class="search-form">
            <input type="text" name="q" placeholder="Search by topic..." value="">
            <button type="submit" data-i18n="search">Search</button>
        </form>
        <?php endif; ?>
        <?php if ($logged_in): ?>
        <nav class="header-nav">
            <a data-i18n="logout" href="<?php echo $base; ?>auth/logout.php">Logout</a>
        </nav>
        <?php else: ?>
        <nav class="header-nav">
            <a data-i18n="login" href="<?php echo $base; ?>auth/login.php">Login</a>
            <a data-i18n="register" href="<?php echo $base; ?>auth/register.php">Register</a>
        </nav>
        <?php endif; ?>
    </header>
    <div id="Content">

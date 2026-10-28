<?php
// includes/layout_header.php
// Cabecera HTML común. Variables opcionales antes del include:
//   $page_title (string, texto sin escapar — el include NO escapa el título)
//   $base       (string, prefijo de rutas; '' en páginas de src/, '../' en admin/ y auth/)
if (!isset($base)) { $base = ''; }
$page_title = $page_title ?? 'Open Pastebin NG';
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
    <div id="Content">

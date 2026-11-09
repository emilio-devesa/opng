<?php

declare(strict_types=1);

// includes/layout_footer.php
// Shared page footer: closes #Content and renders global actions and copyright.
// The theme/language and auth actions now live in the #SiteHeader
// (includes/layout_header.php).
// Variables: $base (path prefix, defaults to '').
if (!isset($base)) {
    $base = '';
}
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/i18n.php';
$logged_in = isset($_SESSION['user_id']);
?>
    </main>
    <footer>
        <a class="back-to-top" href="#SiteHeader" data-i18n="back_to_top">Back to top</a>
        <p class="footer-text">
            &copy; <?php echo date("Y"); ?> <a href="https://github.com/emilio-devesa" target="_blank" rel="noopener noreferrer">Emilio Devesa</a> &mdash; <a href="https://github.com/emilio-devesa/opng" target="_blank" rel="noopener noreferrer">Open Pastebin NG</a> <a href="https://github.com/emilio-devesa/opng" target="_blank" rel="noopener noreferrer">v<?php echo htmlspecialchars(OPNG_VERSION, ENT_QUOTES, 'UTF-8'); ?></a>
        </p>
    </footer>
</body>
</html>
<?php
$opng_document = ob_get_clean();
if (is_string($opng_document)) {
    echo opng_i18n_apply($opng_document);
}
?>

<?php
// includes/layout_footer.php
// Pie de pagina comun: cierra #Content y renderiza el footer con el copyright.
// El tema/idioma y las acciones de auth viven ahora en el #SiteHeader
// (includes/layout_header.php).
// Variables: $base (prefijo de rutas, por defecto '').
if (!isset($base)) { $base = ''; }
$logged_in = isset($_SESSION['user_id']);
?>
        </div>
    <footer>
        <p class="footer-text">
            &copy; <?php echo date("Y"); ?> <a href="https://github.com/emilio-devesa" target="_blank" rel="noopener noreferrer">Emilio Devesa</a> &mdash; <a href="https://github.com/emilio-devesa/opng" target="_blank" rel="noopener noreferrer">Open Pastebin NG</a> <a href="https://github.com/emilio-devesa/opng" target="_blank" rel="noopener noreferrer">v0.8</a>
        </p>
    </footer>
</body>
</html>

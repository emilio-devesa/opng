<?php
// includes/layout_footer.php
// Pie de pagina comun: cierra #Content, renderiza el footer con navegacion.
// El tema/idioma y las acciones de auth viven ahora en el #SiteHeader
// (includes/layout_header.php).
// Variables: $base (prefijo de rutas, por defecto '').
if (!isset($base)) { $base = ''; }
$logged_in = isset($_SESSION['user_id']);
?>
        </div>
    <footer>
        <nav>
            <ul>
                <li><a href="<?php echo $base; ?>index.php">Home</a></li>
                <?php if ($logged_in): ?>
                    <li><a data-i18n="logout" href="<?php echo $base; ?>auth/logout.php">Logout</a></li>
                <?php else: ?>
                    <li><a data-i18n="login" href="<?php echo $base; ?>auth/login.php">Login</a></li>
                    <li><a data-i18n="register" href="<?php echo $base; ?>auth/register.php">Register</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </footer>
</body>
</html>
